<?php

namespace App\Actions\Users;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An invited person sets their password from the emailed link. The token is
 * checked and used up by the "invites" password broker; following the link also
 * confirms the email address.
 */
final class AcceptInvitation
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input  token, email, password, password_confirmation
     *
     * @throws ValidationException
     */
    public function handle(array $input): User
    {
        Validator::make($input, [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ])->validate();

        $accepted = null;

        $status = Password::broker('invites')->reset(
            [
                'email' => Str::lower((string) $input['email']),
                'token' => (string) $input['token'],
                'password' => (string) $input['password'],
                'password_confirmation' => (string) ($input['password_confirmation'] ?? ''),
            ],
            function (User $user, string $password) use (&$accepted): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'remember_token' => Str::random(60),
                ])->save();

                Audit::record($user, 'user.invitation_accepted', $user);
                event(new PasswordReset($user));
                $accepted = $user;
            },
        );

        if ($accepted === null) {
            throw ValidationException::withMessages(['email' => $status === Password::INVALID_USER
                ? 'There is no invitation for that email address.'
                : 'This invitation link is invalid or has expired. Ask an Administrator to send a new one.']);
        }

        return $accepted;
    }
}
