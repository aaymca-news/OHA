<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The Super Administrator deletes a whole assessment: its form, report, ODP, scores,
 * signatures, timeline and files. If it was the movement's only one, the movement
 * shows as not yet assessed again. Nobody else can do this.
 *
 * The audit trail keeps everything it recorded about the assessment, plus a record
 * of the deletion itself: who, when, why, and what the assessment held.
 */
final class DeleteAssessment
{
    use EnforcesPolicy;

    public function handle(Assessment $assessment, User $superAdmin, string $confirmName, string $reason): void
    {
        $this->ensure($superAdmin, 'delete', $assessment);

        $movement = $assessment->movement;
        if (mb_strtolower(trim($confirmName)) !== mb_strtolower($movement->name)) {
            throw new WorkflowRuleBroken("Type the movement’s name, {$movement->name}, to confirm the deletion.");
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new WorkflowRuleBroken('Say why the assessment is being deleted: the audit trail keeps your reason.');
        }

        $files = DB::transaction(function () use ($assessment, $superAdmin, $movement, $reason): array {
            $assessment = Assessment::query()->with(['artefacts', 'score'])->lockForUpdate()->findOrFail($assessment->id);
            $artefactIds = $assessment->artefacts->pluck('id');

            $files = collect()
                ->merge(FormUpload::query()->whereIn('artefact_id', $artefactIds)->get(['disk', 'path'])->map(fn ($f) => ['disk' => $f->disk, 'path' => $f->path]))
                ->merge(Document::query()->whereIn('artefact_id', $artefactIds)->get(['disk', 'path'])->map(fn ($f) => ['disk' => $f->disk, 'path' => $f->path]))
                ->merge(BoardSignature::query()->whereIn('artefact_id', $artefactIds)->get(['signature_disk', 'signature_path'])
                    ->map(fn ($s) => ['disk' => $s->signature_disk, 'path' => $s->signature_path]))
                ->values()->all();

            Audit::record($superAdmin, 'assessment.deleted', $movement, $assessment, payload: array_filter([
                'reason' => trim($reason),
                'period' => $assessment->period_label,
                'opened_at' => $assessment->opened_at->toIso8601String(),
                'states' => $assessment->artefacts->mapWithKeys(fn ($a) => [$a->kind->value => $a->state->value])->all(),
                'points' => $assessment->score?->points_achieved !== null ? (float) $assessment->score->points_achieved : null,
                'files' => count($files),
            ], fn ($v) => $v !== null));

            // Scores point at the form upload they were frozen from, so they go first.
            $assessment->categoryScores()->delete();
            $assessment->delete();

            // Notices that link to it would lead nowhere.
            DB::table('notifications')->whereRaw("split_part(data::jsonb->>'link', '?', 1) = ?", ['/assessments/'.$assessment->id])->delete();

            return $files;
        });

        $this->removeUnreferencedFiles($files);

        $admins = User::query()->whereIn('role', ['admin', 'super_admin'])->where('active', true)->get();
        Notify::send($admins->merge($movement->assessors()->where('active', true)->get()), new WorkflowNotice(
            "Assessment deleted: {$movement->name}",
            "{$superAdmin->name} deleted the {$movement->name} assessment ({$assessment->period_label}): ".trim($reason),
            '/movements/'.$movement->slug, 'serious',
        ), except: $superAdmin);
    }

    /**
     * Files are stored once by content, so the same bytes may belong to another
     * assessment too. A file is removed only when nothing refers to it any more.
     *
     * @param  list<array{disk: string|null, path: string|null}>  $files  a typed signature has no file
     */
    private function removeUnreferencedFiles(array $files): void
    {
        // A typed signature has no file.
        foreach (collect($files)->filter(fn ($f) => $f['disk'] !== null && $f['path'] !== null)->unique(fn ($f) => $f['disk'].'|'.$f['path']) as $file) {
            $stillUsed = FormUpload::query()->where('disk', $file['disk'])->where('path', $file['path'])->exists()
                || Document::query()->where('disk', $file['disk'])->where('path', $file['path'])->exists()
                || BoardSignature::query()->where('signature_disk', $file['disk'])->where('signature_path', $file['path'])->exists();

            if (! $stillUsed) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        }
    }
}
