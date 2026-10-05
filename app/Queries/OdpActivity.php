<?php

namespace App\Queries;

use App\Enums\ArtefactKind;
use App\Enums\DocumentPurpose;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\DriveSyncRun;
use App\Models\User;
use App\Support\Google\GoogleDrive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What changed in the ODPs lately, for the dashboard: the newest versions, uploaded
 * or taken from Google Drive, that this user may see; and whether the automatic
 * reading of Google Drive is working.
 */
final class OdpActivity
{
    public function __construct(private readonly GoogleDrive $drive) {}

    /**
     * @return Collection<int, Document>
     */
    public function latest(User $user, int $limit = 8): Collection
    {
        return Document::query()
            ->where('purpose', DocumentPurpose::Uploaded)
            ->whereHas('artefact', fn ($q) => $q->where('kind', ArtefactKind::Odp))
            ->with(['artefact.assessment.movement', 'artefact.status', 'creator', 'approver'])
            ->latest('id')->limit($limit * 4)->get()
            ->filter(fn (Document $d) => Gate::forUser($user)->allows('download', $d))
            ->take($limit)
            ->each(fn (Document $d) => $d->setAttribute('number', $d->versionNumber()))
            ->values();
    }

    /**
     * @return array{connected: bool, last: DriveSyncRun|null, failing: int}
     */
    public function sync(): array
    {
        return [
            'connected' => $this->drive->configured(),
            'last' => DriveSyncRun::query()->latest('id')->first(),
            // Linked ODPs whose document could not be read on the last attempt.
            'failing' => Artefact::query()->where('kind', ArtefactKind::Odp)->whereNotNull('drive_problem')
                ->whereDoesntHave('signature')->count(),
        ];
    }
}
