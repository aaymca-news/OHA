<?php

namespace Database\Seeders;

use App\Demo\DemoPeople;
use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What a new demo (demo.oha.africaymca.org) starts with: the 23 National Movements (no
 * assessments) and its people, all invented, at example.org (which never receives mail):
 * the Administrator, the AAYMCA assessor (assigned to every movement), and a Board
 * Chairperson and a National General Secretary for each movement. The visitor is any of
 * them in turn, from "Choose who to be", and uploads made-up forms, reports and ODPs.
 *
 * For a ready-made story with invented data at every stage, run DemoStorySeeder after it.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('oha.demo') && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder creates the demo accounts: it runs only on the demo site (OHA_DEMO=true).');
        }

        $this->call(MovementsSeeder::class);

        /** @var array{chairs: array<string, array{name: string, title: string}>, general_secretaries: array<string, array{name: string, title: string}>} $sample */
        $sample = require database_path('data/sample_people.php');
        $movements = Movement::query()->orderBy('name')->get(['id', 'slug', 'name']);
        // Nobody signs in to these with a password: they are one click away. One random,
        // unknown password for all (hashed once: a demo is made when a visitor registers).
        $password = Hash::make(Str::random(40));
        $person = fn (string $email, string $name, Role $role, string $title, ?int $movement = null) => User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => $password, 'email_verified_at' => now(),
            'role' => $role, 'title' => $title, 'movement_id' => $movement, 'active' => true,
        ]);

        // An Administrator, not a Super Administrator: the demo's only Super Administrator
        // is the one who prepares the showcase (oha:demo-super-admin).
        $admin = $person(DemoPeople::STAFF['admin']['email'], 'Wanjiru Kamau', Role::Admin, 'Head of Movement Strengthening (demo)');
        $assessor = $person(DemoPeople::STAFF['assessor']['email'], 'Kofi Asante', Role::Staff, 'Movement Strengthening Officer (demo)');
        // Both on every movement, so My Work shows them all the work, whoever is chosen.
        foreach ([$admin, $assessor] as $staff) {
            $staff->assignedMovements()->syncWithoutDetaching($movements->pluck('id')->all());
        }

        foreach ($movements as $movement) {
            foreach ([[Role::Board, $sample['chairs'], 'Board Chairperson'], [Role::Ngs, $sample['general_secretaries'], 'National General Secretary']] as [$role, $holders, $office]) {
                $name = $holders[$movement->slug]['name'] ?? "{$movement->name} {$office}";
                $person(Str::slug($name, '.').'.'.$movement->slug.'@example.org', $name, $role, "{$office}, {$movement->name} (demo)", $movement->id);
            }
        }
    }
}
