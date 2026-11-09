<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit;
use App\Support\SignedInDevices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signs the user out on one other browser or device, or on every other one, keeping
 * this one. Sessions live in the database, so this is immediate; "Remember me" on
 * those devices stops working too. They can sign in again with their password.
 */
final class SignOutOtherSessions
{
    /**
     * @param  string|null  $deviceKey  one device (its key from SignedInDevices); null for every other one
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $password, string $currentSessionId, ?string $deviceKey = null): int
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $others = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->pluck('id');
        if ($deviceKey !== null) {
            $others = $others->filter(fn (string $id) => hash_equals(SignedInDevices::key($id), $deviceKey));
            if ($others->isEmpty()) {
                throw ValidationException::withMessages(['password' => 'That device is already signed out.']);
            }
        }

        $count = DB::table('sessions')->whereIn('id', $others->all())->delete();
        // A remembered sign-in would otherwise let them straight back in.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        Audit::record($user, 'auth.signed_out_elsewhere', $user, payload: ['sessions' => $count, 'one_device' => $deviceKey !== null]);

        return $count;
    }
}
