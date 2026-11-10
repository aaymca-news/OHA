<?php

namespace Database\Seeders;

use App\Demo\DemoPeople;
use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * For the demo's invented story only (DemoStorySeeder): invented AAYMCA staff, Board
 * Chairpersons and General Secretaries, all at example.org (which never receives mail),
 * and the demo assessor's West African movements. A new private demo has none of them:
 * its visitor invites and assigns people, as on the live site.
 */
class DemoSamplePeopleSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{secretariat: list<array{name: string, role: string, title: string, movements: list<string>}>, chairs: array<string, array{name: string, title: string}>, general_secretaries: array<string, array{name: string, title: string}>} $sample */
        $sample = require database_path('data/sample_people.php');
        $movements = Movement::query()->pluck('id', 'slug');
        $password = Hash::make(Str::random(40));
        $person = fn (string $name, string $email, Role $role, ?string $title = null, ?int $movement = null) => User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => $password, 'email_verified_at' => now(),
            'role' => $role, 'title' => $title, 'movement_id' => $movement,
        ]);

        User::query()->where('email', DemoPeople::ACCOUNTS['assessor']['email'])->firstOrFail()
            ->assignedMovements()->syncWithoutDetaching($movements->only(['ghana', 'togo', 'nigeria', 'liberia', 'sierra-leone', 'benin', 'senegal'])->values()->all());

        foreach ($sample['secretariat'] as $s) {
            if ($s['role'] === 'super_admin') {
                continue;
            }
            $user = $person($s['name'], Str::slug($s['name'], '.').'@example.org', Role::from($s['role']), $s['title']);
            $user->assignedMovements()->syncWithoutDetaching($movements->only($s['movements'])->values()->all());
        }
        foreach ([[Role::Board, $sample['chairs']], [Role::Ngs, $sample['general_secretaries']]] as [$role, $holders]) {
            foreach ($holders as $slug => $h) {
                if ($slug !== DemoPeople::MOVEMENT && isset($movements[$slug])) {
                    $person($h['name'], Str::slug($h['name'], '.').'.'.$slug.'@example.org', $role, $h['title'], $movements[$slug]);
                }
            }
        }
    }
}
