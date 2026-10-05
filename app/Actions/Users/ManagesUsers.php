<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Only an active Administrator manages users, and only a Super Administrator
 * manages the Administrators. A movement has one Board Chairperson.
 */
trait ManagesUsers
{
    private function ensureAdmin(User $actor): void
    {
        $this->ensureAllowed($actor, 'manage', User::class);
    }

    /**
     * @param  mixed  $arguments
     */
    private function ensureAllowed(User $actor, string $ability, $arguments): void
    {
        $response = Gate::forUser($actor)->inspect($ability, $arguments);

        if ($response->denied()) {
            throw new WorkflowRuleBroken((string) $response->message());
        }
    }

    /**
     * Makes room for a new Board Chairperson of $movement. Each movement has one; the
     * current one is deactivated (and signed out), but only when $confirmReplace says so.
     */
    private function vacateChair(User $actor, Movement $movement, ?User $newChair, bool $confirmReplace): void
    {
        $current = User::query()->where('role', Role::Board)->where('movement_id', $movement->id)->where('active', true)
            ->when($newChair !== null, fn ($q) => $q->whereKeyNot($newChair->id))
            ->lockForUpdate()->first();

        if ($current === null) {
            return;
        }

        if (! $confirmReplace) {
            throw new WorkflowRuleBroken("{$current->name} is {$movement->name}’s Board Chairperson, and a movement has one. Confirm to replace them: their account will be deactivated.");
        }

        $current->update(['active' => false]);
        DB::table('sessions')->where('user_id', $current->id)->delete();
        Audit::record($actor, 'user.deactivated', $current, payload: ['reason' => "Replaced as {$movement->name}’s Board Chairperson".($newChair !== null ? " by {$newChair->name}" : '')]);
    }
}
