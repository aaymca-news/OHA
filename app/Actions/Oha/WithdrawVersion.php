<?php

namespace App\Actions\Oha;

use App\Actions\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\VersionPruner;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws the newest version of the report or ODP, saved by mistake, perhaps over a
 * colleague's: the version before it is current again (the platform keeps it for this),
 * and the report or ODP stands as that version left it (approved, or a draft), or as not
 * started if there was none. The withdrawal stays in the audit trail, and the movement's
 * other assessors are told.
 */
final class WithdrawVersion
{
    use EnforcesPolicy, LocksArtefact;

    public function handle(Document $version, User $user): void
    {
        $this->ensure($user, 'withdraw', $version);

        DB::transaction(function () use ($version, $user): void {
            $artefact = $this->lock($version->artefact);
            $this->ensure($user, 'withdraw', $version);

            $assessment = $artefact->assessment;
            $from = $artefact->state;
            [$disk, $path, $name, $number] = [$version->disk, $version->path, $version->original_name, $version->versionNumber()];

            $version->delete();

            $previous = $artefact->documents()->where('purpose', DocumentPurpose::Uploaded)->latest('id')->first();
            $artefact->update(['state' => match (true) {
                $previous === null => ArtefactState::NotStarted,
                $previous->isApproved() => ArtefactState::Approved,
                default => ArtefactState::Drafted,
            }]);
            VersionPruner::removeFileIfUnused($disk, $path);

            $now = $previous !== null ? 'version '.$previous->versionNumber() : null;
            Audit::record($user, $artefact->kind->value.'.version_withdrawn', $artefact, $assessment, $from, $artefact->state,
                payload: array_filter(['version' => $number, 'file' => $name, 'now_current' => $now]));

            $noun = $artefact->kind === ArtefactKind::Odp ? 'ODP' : 'report';
            Notify::colleagues($assessment, $user,
                "Withdrawn: {$noun} version {$number}, {$assessment->movement->name}",
                "{$user->name} withdrew version {$number} of the {$noun} ({$name}).".($now !== null ? ' '.ucfirst($now).' is current again.' : ' No version is left.'));
        });
    }
}
