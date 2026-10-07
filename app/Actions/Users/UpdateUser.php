<?php

namespace App\Actions\Users;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Support\AssessorChanges;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An Administrator corrects a user's name, email or title, and which
 * movements an AAYMCA user is assigned to assess. Only a Super Administrator
 * does this to an Administrator.
 */
final class UpdateUser
{
    use ManagesUsers;

    /**
     * @param  list<int>|null  $movementIds  null leaves the assignments unchanged
     */
    public function handle(User $admin, User $user, string $name, string $email, ?string $title, ?array $movementIds = null): User
    {
        $this->ensureAllowed($admin, 'administer', $user);

        if ($movementIds !== null && $movementIds !== [] && ! $user->isAssessor()) {
            throw new WorkflowRuleBroken('Only AAYMCA users are assigned movements to assess.');
        }

        return DB::transaction(function () use ($admin, $user, $name, $email, $title, $movementIds): User {
            $user->fill([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
            ]);
            $changed = array_keys($user->getDirty());
            $user->save();

            if ($movementIds !== null) {
                $result = $user->assignedMovements()->sync($movementIds);
                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    $changed[] = 'movements';
                }
                // The same record and notices as assigning from the movement's own page.
                foreach (Movement::query()->whereKey($result['attached'])->get() as $movement) {
                    AssessorChanges::record($admin, $movement, collect([$user]), collect());
                }
                foreach (Movement::query()->whereKey($result['detached'])->get() as $movement) {
                    AssessorChanges::record($admin, $movement, collect(), collect([$user]));
                }
            }

            if ($changed !== []) {
                Audit::record($admin, 'user.updated', $user, payload: ['changed' => $changed]);
            }

            return $user;
        });
    }
}
