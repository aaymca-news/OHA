<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * A person corrects their own name and job title. Their email (the sign-in), role and
 * movements are not theirs to change: the Administrators set those.
 */
final class UpdateOwnProfile
{
    public function handle(User $user, string $name, ?string $title): User
    {
        return DB::transaction(function () use ($user, $name, $title): User {
            $user->fill([
                'name' => trim($name),
                'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
            ]);
            $changed = array_keys($user->getDirty());
            $user->save();

            if ($changed !== []) {
                Audit::record($user, 'user.profile_updated', $user, payload: ['changed' => $changed]);
            }

            return $user;
        });
    }
}
