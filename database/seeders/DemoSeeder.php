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
 * What the demo site (demo.oha.africaymca.org) starts with: the 23 National Movements
 * (no assessments), the four one-click demo accounts, and invented AAYMCA staff, Board
 * Chairpersons and General Secretaries, all at example.org (which never receives mail).
 * People then try the platform by uploading made-up forms, reports and ODPs.
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

        /** @var array{secretariat: list<array{name: string, role: string, title: string, movements: list<string>}>, chairs: array<string, array{name: string, title: string}>, general_secretaries: array<string, array{name: string, title: string}>} $sample */
        $sample = require database_path('data/sample_people.php');
        $movements = Movement::query()->pluck('id', 'slug');
        // Nobody signs in with a password here: the demo accounts are one click away. One
        // random, unknown password for all (hashed once: a private copy is made on a click).
        $password = Hash::make(Str::random(40));
        $person = fn (string $name, string $email, Role $role, ?string $title = null, ?int $movement = null) => User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => $password, 'email_verified_at' => now(),
            'role' => $role, 'title' => $title, 'movement_id' => $movement,
        ]);

        $accounts = DemoPeople::ACCOUNTS;
        $person('Wanjiru Kamau', $accounts['admin']['email'], Role::SuperAdmin, 'Head of Movement Strengthening (demo)');
        $assessor = $person('Kofi Asante', $accounts['assessor']['email'], Role::Staff, 'Zonal Coordinator, West Africa (demo)');
        $assessor->assignedMovements()->syncWithoutDetaching($movements->only(['ghana', 'togo', 'nigeria', 'liberia', 'sierra-leone', 'benin', 'senegal'])->values()->all());
        $person('Abena Owusu', $accounts['chair']['email'], Role::Board, 'Board Chairperson (demo)', $movements[DemoPeople::MOVEMENT]);
        $person('Yaw Boateng', $accounts['ngs']['email'], Role::Ngs, 'National General Secretary (demo)', $movements[DemoPeople::MOVEMENT]);

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
