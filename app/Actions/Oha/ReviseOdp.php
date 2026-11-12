<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Support\Audit;
use App\Support\FileVault;
use App\Support\Notify;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\DB;

/**
 * Revises the ODP during a follow-up (decided 12 Nov 2026). Once the assessment is closed,
 * the ODP approved in Stage 1 stays as it was; while a follow-up is open, a revised ODP is
 * kept as a revision, counted in that follow-up, with no separate approval (closing the
 * follow-up is the sign-off). It comes three ways:
 *
 *   upload     re-uploaded by the movement's assessors or an Administrator
 *   fromDrive  a change made in the linked Google Drive document (TakeOdpFromDrive)
 *   adoptNoted when a follow-up is opened, the latest change noted from Google Drive
 *              since the plan's ODP (while no follow-up was open)
 *
 * Its tasks are then matched against the plan (OdpTimeline): kept, added, or removed for
 * the record. The General Secretary and the assessors are told what changed.
 */
final class ReviseOdp
{
    use StoresVersions;

    public function upload(Artefact $odp, string $sourcePath, string $originalName, User $by, ?string $note = null): Document
    {
        $this->ensure($by, 'revise', $odp);

        return $this->store($odp, $sourcePath, $originalName, $by, $by, [
            'source' => DocumentSource::Upload,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
    }

    /** A change made in Google Drive while a follow-up is open: by the platform, under $owner. */
    public function fromDrive(Artefact $odp, string $sourcePath, string $fileName, int $driveVersion, ?string $editorEmail, User $owner, ?User $editor): Document
    {
        return $this->store($odp, $sourcePath, $fileName, $owner, $editor, [
            'source' => DocumentSource::GoogleDrive,
            'drive_version' => $driveVersion,
            'edited_by_email' => $editorEmail,
        ]);
    }

    /**
     * When a follow-up is opened: the latest change noted from Google Drive since the plan's
     * ODP becomes a revision of this follow-up. Null when there is none.
     */
    public function adoptNoted(Assessment $assessment, ?User $by): ?Document
    {
        $odp = $assessment->odp()->first();
        $number = self::openFollowUp($assessment);
        if ($odp === null || $number === null) {
            return null;
        }
        $source = OdpTimeline::source($assessment);
        $noted = $odp->changesAfterSigning()->reorder('id', 'desc')->first();
        if ($noted === null || ($source !== null && ($noted->id < $source->id || $noted->sha256 === $source->sha256))) {
            return null;
        }

        DB::transaction(function () use ($noted, $number, $by, $assessment): void {
            $noted->forceFill(['purpose' => DocumentPurpose::Revision, 'follow_up_number' => $number, 'changes' => null])->save();
            Audit::record($by, 'odp.revised', $noted, $assessment, payload: ['follow_up' => $number, 'file' => $noted->original_name, 'from' => 'google_drive_noted']);
        });

        return $this->after($assessment, $noted->refresh(), $by);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra columns of the revision
     */
    private function store(Artefact $odp, string $sourcePath, string $originalName, User $createdBy, ?User $actor, array $attributes): Document
    {
        $format = $this->versionFormat($odp, $sourcePath, $originalName);
        $assessment = $odp->assessment;
        $number = self::openFollowUp($assessment)
            ?? throw new WorkflowRuleBroken('Open the follow-up first: the ODP is revised during a follow-up.');
        $same = $odp->documents()->where('sha256', hash_file('sha256', $sourcePath))->first();
        if ($same !== null) {
            throw new WorkflowRuleBroken('This file is the same as an ODP already saved ('.$same->original_name.'). Choose the revised file.');
        }

        $stored = FileVault::store($sourcePath, "documents/{$assessment->id}/odp/revisions", $format->value);
        $revision = DB::transaction(function () use ($odp, $assessment, $stored, $attributes, $format, $originalName, $createdBy, $actor, $number): Document {
            $this->lock($odp);
            $revision = $odp->documents()->create($stored + $attributes + [
                'purpose' => DocumentPurpose::Revision,
                'format' => $format,
                'original_name' => $originalName,
                'created_by' => $createdBy->id,
                'created_at' => now(),
                'follow_up_number' => $number,
            ]);
            Audit::record($actor, 'odp.revised', $revision, $assessment, payload: array_filter([
                'follow_up' => $number, 'file' => $originalName, 'sha256' => $stored['sha256'],
                'from' => $revision->fromDrive() ? 'google_drive' : 'upload', 'note' => $revision->note,
            ], fn ($v) => $v !== null));

            return $revision;
        });

        return $this->after($assessment, $revision, $actor);
    }

    /** Reads its tasks into the plan, then tells the General Secretary and the assessors what changed. */
    private function after(Assessment $assessment, Document $revision, ?User $by): Document
    {
        OdpTimeline::refresh($assessment->refresh());
        $revision->refresh();
        $changes = $revision->changes ?? [];
        $counts = collect(['added' => 'added', 'removed' => 'removed', 'reworded' => 'reworded', 'responsible' => 'with a new person responsible'])
            ->map(fn (string $said, string $key) => count($changes[$key] ?? []) > 0 ? count($changes[$key]).' '.(count($changes[$key]) === 1 ? 'task' : 'tasks').' '.$said : null)
            ->filter()->join(', ');
        $movement = $assessment->movement;

        Notify::send(
            collect([$movement->generalSecretary()->first()])->merge(Notify::assessorsOf($assessment)),
            new WorkflowNotice(
                "ODP revised: {$movement->name}",
                ($revision->fromDrive() ? 'A change made in Google Drive' : ($by->name ?? 'Someone').'’s upload')
                    .' revised the ODP during follow-up '.$revision->follow_up_number.'. '
                    .($counts !== '' ? 'In the plan now: '.$counts.'.' : ($assessment->odp_read_problem ?? 'The tasks are unchanged.')),
                route('follow-up.show', $assessment, false), 'info',
            ),
            except: $by,
        );

        return $revision;
    }

    /** The number of the follow-up open now, if one is (and the cycle is not closed). */
    private static function openFollowUp(Assessment $assessment): ?int
    {
        if (! $assessment->isClosed() || $assessment->isFollowUpClosed()) {
            return null;
        }
        $next = FollowUpSchedule::next($assessment);

        return $next !== null && $next['state'] === FollowUpSchedule::OPEN ? $next['number'] : null;
    }
}
