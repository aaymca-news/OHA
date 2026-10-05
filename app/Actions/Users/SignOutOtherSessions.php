<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Signs the user out on every other browser and device, keeping this one.
 * Sessions live in the database, so this is immediate.
 */
final class SignOutOtherSessions
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $password, string $currentSessionId): int
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $count = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete();

        Audit::record($user, 'auth.signed_out_elsewhere', $user, payload: ['sessions' => $count]);

        return $count;
    }
}
