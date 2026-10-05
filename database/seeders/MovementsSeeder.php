<?php

namespace Database\Seeders;

use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the 23 National Movements, with no ratings.
 *
 * Safe for production and safe to re-run: an existing movement is left alone.
 * database/data/movements.php is only the starting point: once loaded, the
 * database is the record, and changes are made there, not in the file.
 */
class MovementsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{slug: string, name: string, country: string, city: string, zone: string, membership: string, planned: array{label: string, on: string|null}|null}> $movements */
        $movements = require database_path('data/movements.php');

        $zones = Zone::query()->pluck('id', 'code');
        $statuses = MembershipStatus::query()->pluck('id', 'code');

        DB::transaction(function () use ($movements, $zones, $statuses): void {
            foreach ($movements as $row) {
                Movement::query()->firstOrCreate(['slug' => $row['slug']], [
                    'name' => $row['name'],
                    'country' => $row['country'],
                    'city' => $row['city'],
                    'zone_id' => $zones[$row['zone']],
                    'membership_status_id' => $statuses[$row['membership']],
                    'planned_assessment_label' => $row['planned']['label'] ?? null,
                    'planned_assessment_on' => $row['planned']['on'] ?? null,
                ]);
            }
        });
    }
}
