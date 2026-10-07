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

    public function handle(Artefact $odp, string $sourcePath, string $originalName, User $uploader, ?string $note = null, ?string $driveUrl = null): Document
    {
        if ($odp->kind !== ArtefactKind::Odp) {
            throw new WorkflowRuleBroken('Only the ODP is uploaded here.');
        }
        $this->ensure($uploader, 'upload', $odp);

        $driveUrl = $driveUrl !== null && trim($driveUrl) !== '' ? trim($driveUrl) : null;
        $fileId = $driveUrl !== null ? DriveLink::fileId($driveUrl) : $odp->drive_file_id;
        if ($fileId === null) {
            throw new WorkflowRuleBroken('Add the link to the ODP in Google Drive, so everyone works on the same document.');
        }

        $version = $this->storeVersion($odp, $sourcePath, $originalName, $uploader, $uploader, 'upload', [
            'source' => DocumentSource::Upload,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);

        if ($driveUrl !== null && $driveUrl !== $odp->drive_url) {
            $this->link->handle($odp->refresh(), $driveUrl, $uploader);
        }

        return $version;
    }
}
