<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Reference data (weights, bands, deadlines) is inserted by its migration, so
     * it exists even without seeding. No assessments are seeded: every movement
     * starts unassessed. Production runs only MovementsSeeder: the
     * 23 movements, with no ratings.
     */
    public function run(): void
    {
        $this->call(MovementsSeeder::class);

        if (! app()->isProduction()) {
            $this->call(SamplePeopleSeeder::class);
        }
    }
}
