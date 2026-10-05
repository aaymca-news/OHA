<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first account on a new server. Everyone else is invited from Users & Roles,
 * but on a fresh database there is nobody to invite them, so the first Super
 * Administrator is created here, on the server itself.
 *
 * It prints a single-use link to set the password (valid 7 days), so it works before
 * email is set up. Run again with the same email to get a fresh link. It refuses once
 * another active Super Administrator exists: from then on, people are invited.
 */
#[Signature('oha:create-super-admin {email : Their email address, which is their sign-in} {name : Their full name, in quotes}')]
#[Description('Create the first Super Administrator and print a link to set their password')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));

        $validator = Validator::make(['email' => $email, 'name' => $name], ['email' => ['required', 'email'], 'name' => ['required', 'min:3', 'max:255']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $others = User::query()->where('role', Role::SuperAdmin)->where('active', true)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))->pluck('email');

        if ($others->isNotEmpty()) {
            $this->error('There is already a Super Administrator ('.$others->implode(', ').'). Invite people from Users & Roles instead.');

            return self::FAILURE;
        }
        if ($existing !== null && $existing->role !== Role::SuperAdmin) {
            $this->error("{$email} already has an account as ".$existing->role->label().'. Use another email address.');

            return self::FAILURE;
        }

        $user = $existing ?? User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'role' => Role::SuperAdmin,
            'active' => true,
        ]);
        if ($existing === null) {
            Audit::record(null, 'user.created_on_server', $user, payload: ['role' => Role::SuperAdmin->value, 'email' => $email]);
        }

        /** @var PasswordBroker $broker */
        $broker = Password::broker('invites');
        $link = route('invitation.show', ['token' => $broker->createToken($user), 'email' => $user->email]);

        $this->info(($existing === null ? 'Created' : 'Found')." the Super Administrator {$user->name} <{$user->email}>.");
        $this->line('Open this link to set the password (it works once, for 7 days):');
        $this->newLine();
        $this->line($link);
        $this->newLine();

        return self::SUCCESS;
    }
}
