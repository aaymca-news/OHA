<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SAMPLE Secretariat users and one Board Chairperson per movement, for local
 * development and demos.
 *
 * Every account gets the password "password", so this seeder refuses to run in
 * production. Real users are invited by an Administrator.
 */
class SamplePeopleSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'aaymca.test';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('SamplePeopleSeeder creates accounts with a known password and must not run in production.');
        }

        /** @var array{secretariat: list<array{name: string, role: string, title: string, movements: list<string>}>, chairs: array<string, array{name: string, title: string}>} $people */
        $people = require database_path('data/sample_people.php');

        $movements = Movement::query()->pluck('id', 'slug');
        $password = Hash::make('password');

        DB::transaction(function () use ($people, $movements, $password): void {
            foreach ($people['secretariat'] as $person) {
                $user = User::query()->firstOrCreate(['email' => $this->email($person['name'])], [
                    'name' => $person['name'],
                    'password' => $password,
                    'email_verified_at' => now(),
                    'role' => Role::from($person['role']),
                    'title' => $person['title'],
                ]);

                $user->assignedMovements()->syncWithoutDetaching(
                    collect($person['movements'])->map(fn (string $slug) => $movements[$slug])->all()
                );
            }

            foreach ($people['chairs'] as $slug => $chair) {
                User::query()->firstOrCreate(['email' => $this->email($chair['name'], $slug)], [
                    'name' => $chair['name'],
                    'password' => $password,
                    'email_verified_at' => now(),
                    'role' => Role::Board,
                    'title' => $chair['title'],
                    'movement_id' => $movements[$slug],
                ]);
            }
        });
    }

    private function email(string $name, ?string $movementSlug = null): string
    {
        $local = Str::slug($name, '.');

        return $movementSlug === null
            ? "{$local}@".self::EMAIL_DOMAIN
            : "{$local}.{$movementSlug}@".self::EMAIL_DOMAIN;
    }
}
