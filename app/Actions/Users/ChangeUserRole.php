<?php

namespace App\Actions\Users;

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
 * A movement has one Board Chairperson and one National General Secretary: giving
 * someone a role the movement already has filled deactivates the current holder,
 * which needs $confirmReplace.
 */
final class ChangeUserRole
{
    use ManagesUsers;

    public function handle(User $actor, User $target, Role $role, ?Movement $chairOf = null, bool $confirmReplace = false): User
    {
        $this->ensure($actor, 'changeRole', $target);
        $this->ensure($actor, 'grantRole', [User::class, $role]);

        if ($role->isMovementRole() && $chairOf === null) {
            throw new WorkflowRuleBroken("A {$role->label()} belongs to a movement: choose which one.");
        }

        return DB::transaction(function () use ($actor, $target, $role, $chairOf, $confirmReplace): User {
            if ($role->isMovementRole() && $target->active) {
                $this->vacate($actor, $role, $chairOf, $target, $confirmReplace);
            }

            $from = $target->role;
            $target->update([
                'role' => $role,
                'movement_id' => $role->isMovementRole() ? $chairOf->id : null,
            ]);
            if ($role->isMovementRole()) {
                $target->assignedMovements()->detach();
            }

            Audit::record($actor, 'user.role_changed', $target, from: $from, to: $role,
                payload: $role->isMovementRole() ? ['movement_id' => $chairOf->id] : []);

            return $target;
        });
    }
}
