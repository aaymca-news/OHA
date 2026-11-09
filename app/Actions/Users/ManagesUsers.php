<?php

namespace App\Actions\Users;

use App\Actions\Concerns\EnforcesPolicy;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\ChairRoleEnded;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Only an active Administrator manages users, and only a Super Administrator
 * manages the Administrators. A movement has one Board Chairperson and one National
 * General Secretary.
 */
trait ManagesUsers
{
    use EnforcesPolicy;

    private function ensureAdmin(User $actor): void
    {
        $this->ensure($actor, 'manage', User::class);
    }

    /**
     * Makes room for a new holder of one of $movement's own roles (Board Chairperson or
     * National General Secretary). Each movement has one of each; the current one is
     * deactivated (and signed out), but only when $confirmReplace says so, and is told by
     * email that their role has ended.
     */
    private function vacate(User $actor, Role $role, Movement $movement, ?User $newHolder, bool $confirmReplace, ?string $newHolderName = null): void
    {
        $current = User::query()->where('role', $role)->where('movement_id', $movement->id)->where('active', true)
            ->when($newHolder !== null, fn ($q) => $q->whereKeyNot($newHolder->id))
            ->lockForUpdate()->first();

        if ($current === null) {
            return;
        }

        if (! $confirmReplace) {
            throw new WorkflowRuleBroken("{$current->name} is {$movement->name}’s {$role->label()}, and a movement has one. Confirm to replace them: their account will be deactivated.");
        }

        $newName = $newHolder->name ?? $newHolderName;
        $current->update(['active' => false]);
        DB::table('sessions')->where('user_id', $current->id)->delete();
        Audit::record($actor, 'user.deactivated', $current, payload: ['reason' => "Replaced as {$movement->name}’s {$role->label()}".($newName !== null ? " by {$newName}" : '')]);

        // Sent to them directly: Notify skips deactivated accounts, and this one just was.
        $current->notify(new ChairRoleEnded($movement->name, $actor->name, $newName, $role->label()));
    }
}
