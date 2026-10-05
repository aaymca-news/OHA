<?php

namespace App\Actions;

use App\Actions\Users\ManagesUsers;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * An Administrator gives a user a new role. Only a Super Administrator gives or
 * takes away an Administrator role, and nobody changes their own.
 *
 * A movement has one Board Chairperson: making someone the Chairperson of a
 * movement that has one deactivates the current one, which needs $confirmReplace.
 */
final class ChangeUserRole
{
    use ManagesUsers;

    public function handle(User $actor, User $target, Role $role, ?Movement $chairOf = null, bool $confirmReplace = false): User
    {
        $this->ensureAllowed($actor, 'changeRole', $target);
        $this->ensureAllowed($actor, 'grantRole', [User::class, $role]);

        if ($role === Role::Board && $chairOf === null) {
            throw new WorkflowRuleBroken('A Board Chairperson belongs to a movement: choose which one.');
        }

        return DB::transaction(function () use ($actor, $target, $role, $chairOf, $confirmReplace): User {
            if ($role === Role::Board && $target->active) {
                $this->vacateChair($actor, $chairOf, $target, $confirmReplace);
            }

            $from = $target->role;
            $target->update([
                'role' => $role,
                'movement_id' => $role === Role::Board ? $chairOf->id : null,
            ]);
            if ($role === Role::Board) {
                $target->assignedMovements()->detach();
            }

            Audit::record($actor, 'user.role_changed', $target, from: $from, to: $role,
                payload: $role === Role::Board ? ['movement_id' => $chairOf->id] : []);

            return $target;
        });
    }
}
