<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\StoresVersions;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Google\DriveUnavailable;
use App\Support\Google\GoogleDrive;
use App\Support\Notify;
use Illuminate\Database\UniqueConstraintViolationException;

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

        $skip = match (true) {
            $odp->kind !== ArtefactKind::Odp || $odp->drive_file_id === null => 'No Google Drive document is linked.',
            $odp->isSigned() => 'The ODP is signed, so it is frozen.',
            $odp->state === ArtefactState::PendingApproval => 'The ODP is with the Administrators: changes in Google Drive are taken once they decide.',
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

    /** Records that the document was read, and why it could not be, if it could not. */
    private function checked(Artefact $odp, ?string $problem = null, ?int $version = null): void
    {
        $odp->forceFill(['drive_checked_at' => now(), 'drive_problem' => $problem]
            + ($version !== null ? ['drive_version' => $version] : []))->save();
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
