<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\FileVault;
use App\Support\Google\DriveFile;
use App\Support\Google\DriveUnavailable;
use App\Support\Google\GoogleDrive;
use App\Support\Notify;
use App\Support\OdpChanges;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the ODP's linked Google Drive document and, if it changed since the platform
 * last read it, saves it as a new version: exactly as if someone had uploaded it.
 * Run every few minutes for every linked ODP, and on demand from the ODP tab.
 *
 * - A document still being edited is left until nobody has changed it for a while
 *   (oha.drive.quiet_minutes), so one sitting of edits becomes one version.
 * - Nothing is taken while the ODP is with the Administrators: the change is taken
 *   once they decide. Nothing is taken once the Board Chairperson has signed.
 * - A change after approval needs approval again; everyone else keeps seeing the last
 *   approved version meanwhile.
 * - The version records the Google account that last changed the document. If that
 *   person has an account here, the version is theirs; if not, it is recorded under
 *   whoever linked the document, with the Google address kept.
 */
final class TakeOdpFromDrive
{
    use StoresVersions;

    public const SAVED = 'saved';

    public const UNCHANGED = 'unchanged';

    public const WAITING = 'waiting';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function __construct(private readonly GoogleDrive $drive) {}

    /**
     * @return array{outcome: string, message: string, version: Document|null}
     */
    public function handle(Artefact $odp, ?User $requestedBy = null): array
    {
        if ($requestedBy !== null) {
            $this->ensure($requestedBy, 'syncDrive', $odp);
        }

        $signed = $odp->isSigned();
        $skip = match (true) {
            $odp->kind !== ArtefactKind::Odp || $odp->drive_file_id === null => 'No Google Drive document is linked.',
            ! $signed && $odp->state === ArtefactState::PendingApproval => 'The ODP is with the Administrators: changes in Google Drive are taken once they decide.',
            ! $this->drive->configured() => 'The platform is not connected to Google Drive yet, so changes there are not taken automatically. Upload the ODP to add a version.',
            default => null,
        };
        if ($skip !== null) {
            return $this->result(self::SKIPPED, $skip);
        }

        try {
            $file = $this->drive->file((string) $odp->drive_file_id);
            if ($file->version === $odp->drive_version) {
                $this->checked($odp);

                return $this->result(self::UNCHANGED, 'Nothing has changed in Google Drive since the last version.');
            }
            $quiet = (int) config('oha.drive.quiet_minutes');
            if ($requestedBy === null && $file->modifiedAt->isAfter(now()->subMinutes($quiet))) {
                $this->checked($odp);

                return $this->result(self::WAITING, "The document is being edited. It is taken once nobody has changed it for {$quiet} minutes.");
            }
            $bytes = $this->drive->content($file);
        } catch (DriveUnavailable $e) {
            $this->checked($odp, $e->getMessage());

            return $this->result(self::FAILED, $e->getMessage());
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'odp');
        file_put_contents($path, $bytes);

        // Signed: the ODP is frozen, so a change is noted for Stage 2, never made a version.
        if ($signed) {
            try {
                return $this->noteAfterSigning($odp, $file, $path, $requestedBy);
            } finally {
                @unlink($path);
            }
        }

        try {
            $same = $odp->versions()->where('sha256', hash_file('sha256', $path))->first();
            if ($same !== null) {
                $this->checked($odp, version: $file->version);

                return $this->result(self::UNCHANGED, 'The document in Google Drive is the same as version '.$same->versionNumber().'.');
            }

            $editor = $file->editorEmail !== null
                ? User::query()->whereRaw('lower(email) = ?', [mb_strtolower($file->editorEmail)])->first()
                : null;
            $owner = $editor ?? $odp->driveLinker ?? $requestedBy
                ?? throw new WorkflowRuleBroken('Nobody here can be recorded for this change. Link the document again.');
            $approved = $odp->approvedVersion()->first();
            $wasApproved = $odp->isApproved();

            $version = $this->storeVersion($odp, $path, $file->fileName(), $owner, $editor ?? $requestedBy, null, [
                'source' => DocumentSource::GoogleDrive,
                'drive_version' => $file->version,
                'edited_by_email' => $file->editorEmail,
            ], [
                'drive_version' => $file->version,
                'edited_by' => $file->editorName !== null ? "{$file->editorName} <{$file->editorEmail}>" : $file->editorEmail,
                'checked_by' => $requestedBy?->name,
            ]);
            $this->checked($odp, version: $file->version);
        } catch (WorkflowRuleBroken $e) {
            $this->checked($odp, $e->getMessage());

            return $this->result(self::FAILED, $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            // Taken a moment ago by another check.
            return $this->result(self::UNCHANGED, 'This change was just taken.');
        } finally {
            @unlink($path);
        }

        $this->tell($odp, $version, $file->editorName ?? $file->editorEmail ?? 'Someone', $wasApproved ? $approved : null, $editor);

        return $this->result(self::SAVED, 'Saved the document in Google Drive as version '.$version->versionNumber().'.', $version);
    }

    /**
     * A change made in Google Drive to the signed ODP: kept as a copy "changed after
     * signing", with what changed since the copy before (or the signed version). The
     * signed version stays the plan of record.
     *
     * @return array{outcome: string, message: string, version: Document|null}
     */
    private function noteAfterSigning(Artefact $odp, DriveFile $file, string $path, ?User $requestedBy): array
    {
        $previous = $odp->changesAfterSigning()->reorder('id', 'desc')->first() ?? $odp->signature?->document;
        $sha = (string) hash_file('sha256', $path);
        if ($previous !== null && $previous->sha256 === $sha) {
            $this->checked($odp, version: $file->version);

            return $this->result(self::UNCHANGED, 'Nothing has changed in Google Drive since it was last noted.');
        }

        $format = $file->format() ?? 'xlsx';
        $before = null;
        if ($previous !== null && $previous->format->value === $format) {
            $before = (string) tempnam(sys_get_temp_dir(), 'odp');
            file_put_contents($before, Storage::disk($previous->disk)->get($previous->path));
        }
        $changes = $before !== null ? OdpChanges::between($before, $path, $format) : ['kind' => 'file', 'count' => 1, 'truncated' => false];
        if ($before !== null) {
            @unlink($before);
        }

        $editor = $file->editorEmail !== null
            ? User::query()->whereRaw('lower(email) = ?', [mb_strtolower($file->editorEmail)])->first()
            : null;
        $owner = $editor ?? $odp->driveLinker ?? $requestedBy ?? $odp->signature->signer
            ?? throw new WorkflowRuleBroken('Nobody here can be recorded for this change. Link the document again.');
        $stored = FileVault::store($path, "documents/{$odp->assessment_id}/odp/after-signing", $format);

        $noted = $odp->documents()->create($stored + [
            'purpose' => DocumentPurpose::AfterSigning,
            'source' => DocumentSource::GoogleDrive,
            'format' => $format,
            'original_name' => $file->fileName(),
            'created_by' => $owner->id,
            'created_at' => now(),
            'drive_version' => $file->version,
            'edited_by_email' => $file->editorEmail,
            'changes' => $changes,
        ]);
        $this->checked($odp, version: $file->version);

        $assessment = $odp->assessment;
        Audit::record($editor ?? $requestedBy, 'odp.changed_after_signing', $noted, $assessment, payload: [
            'drive_version' => $file->version, 'edited_by' => $file->editorEmail, 'changes' => $changes['count'],
        ]);

        $who = $file->editorName ?? $file->editorEmail ?? 'Someone';
        $what = match ($changes['kind']) {
            'cells' => $changes['count'].' '.($changes['count'] === 1 ? 'cell' : 'cells'),
            'lines' => $changes['count'].' '.($changes['count'] === 1 ? 'line' : 'lines'),
            default => 'the file',
        };
        Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
            "Signed ODP changed in Google Drive: {$assessment->movement->name}",
            "{$who} changed {$what} of the signed ODP in Google Drive. The change is noted on the ODP tab; the signed version stays the plan of record.",
            Notify::link($assessment), 'info',
        ), except: $editor);

        return $this->result(self::SAVED, 'Noted the change made after signing ('.$what.').', $noted);
    }

    /**
     * Records that the document was read, and why it could not be, if it could not. When the
     * link stops working (the file deleted, moved out of reach, or no longer shared), the
     * movement's assessors and whoever linked it are told; and again once it works.
     */
    private function checked(Artefact $odp, ?string $problem = null, ?int $version = null): void
    {
        $before = $odp->drive_problem;
        $odp->forceFill(['drive_checked_at' => now(), 'drive_problem' => $problem]
            + ($version !== null ? ['drive_version' => $version] : []))->save();

        if (($before === null) === ($problem === null)) {
            return;
        }

        $assessment = $odp->assessment;
        $movement = $assessment->movement->name;
        Notify::send(Notify::assessorsOf($assessment)->push($odp->driveLinker)->filter()->unique('id')->values(), $problem !== null
            ? new WorkflowNotice(
                "The ODP’s Google Drive link has stopped working: {$movement}",
                "The platform could not read the ODP in Google Drive: {$problem} If the staff now work in another copy, change the link on the ODP tab.",
                Notify::link($assessment), 'serious',
            )
            : new WorkflowNotice(
                "The ODP’s Google Drive link works again: {$movement}",
                'The platform can read the ODP in Google Drive again; changes made there are taken as before.',
                Notify::link($assessment), 'good',
            ));
    }

    /** The movement's assessors hear of each change; a change to an approved ODP needs them to resubmit it. */
    private function tell(Artefact $odp, Document $version, string $editor, ?Document $approved, ?User $editorHere): void
    {
        $assessment = $odp->assessment;
        $movement = $assessment->movement->name;
        $number = $version->versionNumber();

        Notify::send(Notify::assessorsOf($assessment), $approved !== null
            ? new WorkflowNotice(
                "Approved ODP changed in Google Drive: {$movement}",
                "{$editor} changed the approved ODP in Google Drive. The platform saved it as version {$number}, which needs approval again: submit it when it is ready. Until then, staff and the Board Chairperson see version {$approved->versionNumber()}.",
                Notify::link($assessment), 'action',
            )
            : new WorkflowNotice(
                "ODP updated from Google Drive: {$movement}",
                "{$editor} changed the ODP in Google Drive. The platform saved it as version {$number}.",
                Notify::link($assessment), 'info',
            ), except: $editorHere);
    }

    /**
     * @return array{outcome: string, message: string, version: Document|null}
     */
    private function result(string $outcome, string $message, ?Document $version = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'version' => $version];
    }
}
