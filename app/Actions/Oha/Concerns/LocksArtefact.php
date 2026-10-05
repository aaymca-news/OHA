<?php

namespace App\Actions\Oha\Concerns;

use App\Models\Artefact;

/**
 * Re-reads an artefact with a row lock, inside the caller's transaction, so two
 * people acting on it at the same moment cannot both succeed: the second waits,
 * then sees the first one's change and is refused by the policy.
 */
trait LocksArtefact
{
    private function lock(Artefact $artefact): Artefact
    {
        return Artefact::query()->whereKey($artefact->id)->lockForUpdate()->firstOrFail();
    }
}
