<?php

namespace App\Actions\Oha\Concerns;

use App\Enums\ArtefactState;
use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use App\Support\FileVault;
use Illuminate\Support\Facades\DB;

/**
 * Saves a version of the report or ODP: a Word (.docx) or PDF file (the ODP also an
 * Excel workbook), stored once and never overwritten. Saving a version makes the
 * document a draft again, so a version
 * saved after approval goes back to the Administrators; until then everyone else
 * keeps seeing the last approved version.
 */
trait StoresVersions
{
    use EnforcesPolicy, LocksArtefact;

    /**
     * @param  string|null  $ability  checked under the row lock; null when the platform itself saves (Google Drive)
     * @param  array<string, mixed>  $attributes  extra columns of the version
     * @param  array<string, mixed>  $payload  extra audit details
     */
    private function storeVersion(
        Artefact $artefact,
        string $sourcePath,
        string $originalName,
        User $createdBy,
        ?User $actor,
        ?string $ability,
        array $attributes = [],
        array $payload = [],
    ): Document {
        $format = $this->versionFormat($artefact, $sourcePath, $originalName);

        $same = $artefact->versions()->where('sha256', hash_file('sha256', $sourcePath))->first();
        if ($same !== null) {
            throw new WorkflowRuleBroken('This file is identical to version '.$same->versionNumber().'. Choose the changed file.');
        }

        $assessment = $artefact->assessment;
        $stored = FileVault::store($sourcePath, "documents/{$assessment->id}/{$artefact->kind->value}", $format->value);

        return DB::transaction(function () use ($artefact, $assessment, $originalName, $format, $createdBy, $actor, $ability, $stored, $attributes, $payload): Document {
            $artefact = $this->lock($artefact);
            if ($ability !== null && $actor !== null) {
                $this->ensure($actor, $ability, $artefact);
            }
            // Whoever saves it: never while it is with the Administrators, never once signed.
            if ($artefact->state === ArtefactState::PendingApproval) {
                throw new WorkflowRuleBroken('It is with the Administrators for approval. A new version can be added once they decide.');
            }
            if ($artefact->signature()->exists()) {
                throw new WorkflowRuleBroken('It is signed by the Board Chairperson, so it is frozen.');
            }

            $version = $artefact->documents()->create($stored + $attributes + [
                'purpose' => DocumentPurpose::Uploaded,
                'format' => $format,
                'original_name' => $originalName,
                'created_by' => $createdBy->id,
                'created_at' => now(),
            ]);

            $from = $artefact->state;
            $artefact->update(['state' => ArtefactState::Drafted]);

            Audit::record($actor, $artefact->kind->value.'.version_'.($version->fromDrive() ? 'from_drive' : 'uploaded'), $version, $assessment, $from, $artefact->state,
                payload: array_filter([
                    'version' => $version->versionNumber(),
                    'file' => $originalName,
                    'sha256' => $stored['sha256'],
                    'note' => $version->note,
                ] + $payload, fn ($v) => $v !== null));

            return $version;
        });
    }

    /**
     * A version is a Word or PDF file within the size limit. The ODP may also be an
     * Excel workbook: AAYMCA's ODP template is one.
     */
    private function versionFormat(Artefact $artefact, string $sourcePath, string $originalName): DocumentFormat
    {
        $odp = $artefact->kind->value === 'odp';
        $allowed = $odp ? [DocumentFormat::Docx, DocumentFormat::Xlsx, DocumentFormat::Pdf] : [DocumentFormat::Docx, DocumentFormat::Pdf];
        $format = DocumentFormat::tryFrom(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)));
        if (! in_array($format, $allowed, true)) {
            throw new WorkflowRuleBroken($odp
                ? 'The ODP must be an Excel (.xlsx), Word (.docx) or PDF file.'
                : 'The report must be a Word (.docx) or PDF file.');
        }
        if (filesize($sourcePath) > (int) config('oha.max_upload_kb') * 1024) {
            throw new WorkflowRuleBroken('The file is larger than '.((int) config('oha.max_upload_kb') / 1024).' MB.');
        }

        return $format;
    }
}
