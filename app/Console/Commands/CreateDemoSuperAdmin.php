<?php

namespace App\Console\Commands;

use App\Demo\DemoSite;
use App\Demo\Sandboxes;
use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The demo site only, run on the server: creates (or updates) the demo's one Super
 * Administrator, in the showcase, with the password typed here (it is asked for, never
 * shown or kept anywhere but hashed in the database). They sign in at demo/showcase.
 * Run it again to change the password or the name.
 */
#[Signature('oha:demo-super-admin {email : Their sign-in email} {name : The name shown, in quotes}')]
#[Description('Demo site only: create or update the showcase Super Administrator, asking for the password')]
class CreateDemoSuperAdmin extends Command
{
    public function handle(): int
    {
        if (($refusal = DemoSite::refusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $password = (string) $this->secret('Password (not shown as you type)');
        $again = (string) $this->secret('The same password again');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $again],
            ['email' => ['required', 'email'], 'name' => ['required', 'max:255'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]],
        );
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        // In the showcase (the main schema), whatever else this process used.
        Sandboxes::leave();
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make($password), 'role' => Role::SuperAdmin, 'active' => true,
        ]);
        // Their own account, not an invitation waiting to be accepted (a field set directly:
        // it is not among those filled in bulk).
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        // The only Super Administrator: anyone else who was one becomes an Administrator.
        User::query()->where('role', Role::SuperAdmin)->whereKeyNot($user->id)->update(['role' => Role::Admin]);
        // Assigned to every movement, so they can start and run any assessment in the showcase.
        $user->assignedMovements()->syncWithoutDetaching(Movement::query()->pluck('id')->all());

        $this->info("{$user->name} ({$user->email}) is the demo's Super Administrator. Sign in at ".url('demo/showcase'));

        return self::SUCCESS;
    }
}
