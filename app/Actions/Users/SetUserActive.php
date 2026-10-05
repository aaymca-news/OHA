<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates an account. Accounts the audit trail refers to are
 * never deleted. A deactivated user is signed out everywhere at once. Only a Super
 * Administrator does this to an Administrator.
 */
final class SetUserActive
{
    use ManagesUsers;

    public function handle(User $admin, User $user, bool $active): User
    {
        $this->ensureAllowed($admin, 'administer', $user);

        if ($admin->id === $user->id) {
            throw new WorkflowRuleBroken('You cannot deactivate your own account.');
        }

        return DB::transaction(function () use ($admin, $user, $active): User {
            if ($user->active === $active) {
                return $user;
            }

            if ($active && $user->role === Role::Board) {
                $current = $user->movement->chair()->first();
                if ($current !== null) {
                    throw new WorkflowRuleBroken("{$current->name} is now {$user->movement->name}’s Board Chairperson, and a movement has one. Deactivate them first.");
                }
            }

            $user->update(['active' => $active]);

            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            Audit::record($admin, $active ? 'user.reactivated' : 'user.deactivated', $user);

            return $user;
        });
    }
}
