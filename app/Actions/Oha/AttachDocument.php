<?php

namespace App\Actions\Oha;

use App\Enums\ArtefactKind;
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
 * Stores a file supplied for reference against the ODP, so it can be downloaded.
 * (Versions of the report and ODP: UploadReportVersion, UploadOdpVersion.)
 */
final class AttachDocument
{
    public function handle(Artefact $artefact, string $sourcePath, string $originalName, DocumentPurpose $purpose, User $by): Document
    {
        if ($artefact->kind !== ArtefactKind::Odp) {
            throw new WorkflowRuleBroken($artefact->kind === ArtefactKind::Report
                ? 'The report is uploaded as a version of the report, not attached as a document.'
                : 'An OHA form is uploaded as a form, not attached as a document.');
        }
        if ($purpose === DocumentPurpose::Uploaded) {
            throw new WorkflowRuleBroken('A version of the ODP is uploaded as a version, not attached for reference.');
        }

        $format = DocumentFormat::tryFrom(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)));
        if ($format === null) {
            throw new WorkflowRuleBroken('Reports and ODPs are kept as Word (.docx), PDF or Excel (.xlsx) files.');
        }

        $assessment = $artefact->assessment;
        $stored = FileVault::store($sourcePath, "documents/{$assessment->id}/{$artefact->kind->value}", $format->value);

        return DB::transaction(function () use ($artefact, $assessment, $originalName, $purpose, $format, $by, $stored): Document {
            $document = $artefact->documents()->firstOrCreate(['sha256' => $stored['sha256']], $stored + [
                'purpose' => $purpose,
                'format' => $format,
                'original_name' => $originalName,
                'created_by' => $by->id,
                'created_at' => now(),
            ]);

            if ($document->wasRecentlyCreated) {
                Audit::record($by, 'document.attached', $document, $assessment, payload: [
                    'artefact' => $artefact->kind->value,
                    'purpose' => $purpose->value,
                    'file' => $originalName,
                ]);
            }

            return $document;
        });
    }
}
