<?php

namespace App\Support;

use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use App\Models\Artefact;
use App\Models\BoardSignature;
use App\Models\CategoryScore;
use App\Models\Document;
use App\Models\FormUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stage 1 keeps only what is needed, to save space: of the OHA form, the report and the
 * ODP, the last approved version (what everyone sees) and the newest (what is being
 * worked on). Versions in between are removed, with their files once nothing else uses
 * them. What happened stays in the audit trail. When the ODP is signed, only what was
 * approved is kept (finalise()); from then on the record is frozen.
 */
final class VersionPruner
{
    /** The report's or ODP's versions. */
    public static function versions(Artefact $artefact): void
    {
        if ($artefact->assessment->isFrozen()) {
            return;
        }

        $all = $artefact->documents()->where('purpose', DocumentPurpose::Uploaded)->orderBy('id')->get();
        $keep = array_filter([
            $all->last()?->id,
            $all->whereNotNull('approved_at')->last()?->id,
        ]);
        $signed = BoardSignature::query()->where('artefact_id', $artefact->id)->pluck('document_id')->filter()->all();

        foreach ($all as $version) {
            if (! in_array($version->id, [...$keep, ...$signed], true)) {
                [$disk, $path] = [$version->disk, $version->path];
                $version->delete();
                self::removeFileIfUnused($disk, $path);
            }
        }
    }

    /** The OHA form's uploads: the approved one (whose score is recorded) and the newest. */
    public static function uploads(Artefact $form): void
    {
        if ($form->assessment->isFrozen()) {
            return;
        }

        $all = $form->uploads()->orderBy('id')->get();
        $keep = array_filter([
            $all->last()?->id,
            $all->whereNotNull('approved_at')->last()?->id,
            ...CategoryScore::query()->where('assessment_id', $form->assessment_id)->distinct()->pluck('form_upload_id')->all(),
        ]);

        foreach ($all as $upload) {
            if (! in_array($upload->id, $keep, true)) {
                $files = [[$upload->disk, $upload->path], [$upload->edited_disk, $upload->edited_path]];
                $upload->delete();
                foreach ($files as [$disk, $path]) {
                    self::removeFileIfUnused($disk, $path);
                }
            }
        }
    }

    /**
     * When the Board Chairperson signs the ODP, the OHA form and report it rests on are
     * final as approved: any work on them since (newer uploads or versions, a submission
     * waiting for approval) is removed, and both go back to approved. Answers typed into
     * the approved upload since are dropped by SupplyAnswer::restoreApproved(). Returns
     * how many uploads and versions were removed.
     */
    public static function finalise(Artefact $form, Artefact $report): int
    {
        $removed = 0;

        $approvedUpload = $form->uploads()->whereNotNull('approved_at')->orderByDesc('id')->first();
        if ($approvedUpload !== null) {
            $keep = [$approvedUpload->id, ...CategoryScore::query()->where('assessment_id', $form->assessment_id)->distinct()->pluck('form_upload_id')->all()];
            foreach ($form->uploads()->whereNotIn('id', $keep)->get() as $upload) {
                $files = [[$upload->disk, $upload->path], [$upload->edited_disk, $upload->edited_path]];
                $upload->delete();
                foreach ($files as [$disk, $path]) {
                    self::removeFileIfUnused($disk, $path);
                }
                $removed++;
            }
            self::backToApproved($form, $approvedUpload->approved_by, $approvedUpload->approved_at);
        }

        $approvedVersion = $report->documents()->where('purpose', DocumentPurpose::Uploaded)->whereNotNull('approved_at')->orderByDesc('id')->first();
        if ($approvedVersion !== null) {
            foreach ($report->documents()->where('purpose', DocumentPurpose::Uploaded)->where('id', '>', $approvedVersion->id)->get() as $version) {
                [$disk, $path] = [$version->disk, $version->path];
                $version->delete();
                self::removeFileIfUnused($disk, $path);
                $removed++;
            }
            self::backToApproved($report, $approvedVersion->approved_by, $approvedVersion->approved_at);
        }

        return $removed;
    }

    private static function backToApproved(Artefact $artefact, mixed $by, mixed $at): void
    {
        if ($artefact->state !== ArtefactState::Approved) {
            $artefact->update(['state' => ArtefactState::Approved, 'approved_by' => $by, 'approved_at' => $at, 'returned_at' => null]);
        }
    }

    /** A stored file goes once no upload, copy or version refers to it, after the change is saved. */
    public static function removeFileIfUnused(?string $disk, ?string $path): void
    {
        if ($disk === null || $path === null) {
            return;
        }

        DB::afterCommit(function () use ($disk, $path): void {
            $used = Document::query()->where('disk', $disk)->where('path', $path)->exists()
                || FormUpload::query()->where('disk', $disk)->where('path', $path)->exists()
                || FormUpload::query()->where('edited_disk', $disk)->where('edited_path', $path)->exists();
            if (! $used) {
                Storage::disk($disk)->delete($path);
            }
        });
    }
}
