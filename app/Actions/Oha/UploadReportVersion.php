<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\ArtefactKind;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use App\Oha\Report\ReportReader;

/**
 * Saves a version of the health assessment report, written outside the system,
 * as a Word (.docx) or PDF file. Every version is kept; the newest is the one
 * submitted for approval.
 *
 * A new version can be saved at any time except while one is with the
 * Administrators. Saving one after the report was approved re-opens it for
 * approval, and until the new version is approved, staff and the Board
 * Chairperson go on seeing the last approved version.
 */
final class UploadReportVersion
{
    use StoresVersions;

    public function __construct(private readonly ReportReader $reader) {}

    public function handle(Artefact $report, string $sourcePath, string $originalName, User $uploader, ?string $note = null): Document
    {
        if ($report->kind !== ArtefactKind::Report) {
            throw new WorkflowRuleBroken('Only the report is uploaded here.');
        }
        $this->ensure($uploader, 'upload', $report);

        // Read once, kept with the version: the report check reruns against the current form from this.
        $read = $this->reader->read($sourcePath, $this->versionFormat($report, $sourcePath, $originalName)->value);

        return $this->storeVersion($report, $sourcePath, $originalName, $uploader, $uploader, 'upload', [
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'extracted' => $read->toArray(),
        ]);
    }
}
