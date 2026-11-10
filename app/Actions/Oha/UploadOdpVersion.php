<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\ArtefactKind;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use App\Support\Google\DriveLink;

/**
 * Saves a version of the ODP, uploaded by the movement's assessors or an
 * Administrator as an Excel (.xlsx), Word (.docx) or PDF file; AAYMCA's ODP template
 * is a workbook. The ODP is written in Google Drive (a Google Sheet, usually), so it
 * must carry the link to that file: given with the first version, and changeable later.
 *
 * As with the report, a version saved after approval goes back to the
 * Administrators, and everyone else keeps seeing the last approved version until
 * then. Once the Board Chairperson has signed, the ODP is frozen.
 */
final class UploadOdpVersion
{
    use StoresVersions;

    public function __construct(private readonly LinkOdpToDrive $link) {}

    /**
     * @param  array<string, string>  $replacing  what the audit trail keeps when it replaces a version (ReplaceGuard)
     */
    public function handle(Artefact $odp, string $sourcePath, string $originalName, User $uploader, ?string $note = null, ?string $driveUrl = null, array $replacing = []): Document
    {
        if ($odp->kind !== ArtefactKind::Odp) {
            throw new WorkflowRuleBroken('Only the ODP is uploaded here.');
        }
        $this->ensure($uploader, 'upload', $odp);

        $driveUrl = $driveUrl !== null && trim($driveUrl) !== '' ? trim($driveUrl) : null;
        $fileId = $driveUrl !== null ? DriveLink::fileId($driveUrl) : $odp->drive_file_id;
        // The demo site lets people try the ODP without a Google Drive document.
        if ($fileId === null && ! config('oha.demo')) {
            throw new WorkflowRuleBroken('Add the link to the ODP in Google Drive, so everyone works on the same document.');
        }

        $version = $this->storeVersion($odp, $sourcePath, $originalName, $uploader, $uploader, 'upload', [
            'source' => DocumentSource::Upload,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ], $replacing);

        if ($driveUrl !== null && $driveUrl !== $odp->drive_url) {
            $this->link->handle($odp->refresh(), $driveUrl, $uploader);
        }

        return $version;
    }
}
