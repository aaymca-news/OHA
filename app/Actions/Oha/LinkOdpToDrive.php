<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Models\Artefact;
use App\Models\User;
use App\Support\Audit;
use App\Support\Google\DriveLink;
use App\Support\Google\DriveUnavailable;
use App\Support\Google\GoogleDrive;
use Illuminate\Support\Facades\DB;

/**
 * Links the ODP to the Google Drive document the staff write it in. From then on,
 * anyone who may see the work in progress can open it in Google Drive, and, once the
 * platform has access to Google Drive, each change made there is taken as a new version.
 *
 * Linking never fails because the platform cannot open the document yet: the link is
 * kept, and the ODP tab says what to share it with.
 */
final class LinkOdpToDrive
{
    use EnforcesPolicy, LocksArtefact;

    public function __construct(private readonly GoogleDrive $drive) {}

    public function handle(Artefact $odp, string $url, User $by): Artefact
    {
        $this->ensure($by, 'linkDrive', $odp);
        $fileId = DriveLink::fileId($url);

        return DB::transaction(function () use ($odp, $url, $fileId, $by): Artefact {
            $odp = $this->lock($odp);
            $this->ensure($by, 'linkDrive', $odp);

            $previous = $odp->drive_url;
            $sameFile = $odp->drive_file_id === $fileId;

            $odp->update([
                'drive_file_id' => $fileId,
                'drive_url' => trim($url),
                'drive_linked_by' => $by->id,
                'drive_linked_at' => now(),
            ] + ($sameFile ? [] : ['drive_version' => null, 'drive_checked_at' => null, 'drive_problem' => $this->problemOpening($fileId)]));

            if (! $sameFile) {
                Audit::record($by, 'odp.drive_linked', $odp, $odp->assessment, payload: array_filter([
                    'link' => trim($url),
                    'previous' => $previous,
                ]));
            }

            return $odp;
        });
    }

    /** Whether the platform can open the document, when it is connected to Google Drive. */
    private function problemOpening(string $fileId): ?string
    {
        if (! $this->drive->configured()) {
            return null;
        }

        try {
            $this->drive->file($fileId);

            return null;
        } catch (DriveUnavailable $e) {
            return $e->getMessage();
        }
    }
}
