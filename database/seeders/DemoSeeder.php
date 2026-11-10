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
 * assessments) and the four one-click demo people, at example.org (which never receives
 * mail), the assessor assigned to nothing yet. Nobody else: the visitor invites and
 * assigns people, as on the live site, and uploads made-up forms, reports and ODPs.
 *
 * For a ready-made story with invented people and data at every stage, run
 * DemoStorySeeder after it.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('oha.demo') && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder creates the demo accounts: it runs only on the demo site (OHA_DEMO=true).');
        }

        $this->call(MovementsSeeder::class);

        $ghana = Movement::query()->where('slug', DemoPeople::MOVEMENT)->value('id');
        // Nobody signs in to these with a password: they are one click away. One random,
        // unknown password for all (hashed once: a private demo is made on a click).
        $password = Hash::make(Str::random(40));
        $person = fn (string $key, string $name, Role $role, string $title, ?int $movement = null) => User::query()->firstOrCreate(['email' => DemoPeople::ACCOUNTS[$key]['email']], [
            'name' => $name, 'password' => $password, 'email_verified_at' => now(),
            'role' => $role, 'title' => $title, 'movement_id' => $movement,
        ]);

        $person('admin', 'Wanjiru Kamau', Role::SuperAdmin, 'Head of Movement Strengthening (demo)');
        $person('assessor', 'Kofi Asante', Role::Staff, 'Zonal Coordinator, West Africa (demo)');
        $person('chair', 'Abena Owusu', Role::Board, 'Board Chairperson (demo)', $ghana);
        $person('ngs', 'Yaw Boateng', Role::Ngs, 'National General Secretary (demo)', $ghana);
    }
}
