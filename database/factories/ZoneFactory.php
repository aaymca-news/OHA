<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The real zones are inserted by the reference-data migration; this makes extra ones for tests.
 *
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('zone-????'),
            'name' => fake()->unique()->city().' Zone',
            'sort_order' => fake()->numberBetween(10, 99),
        ];
    }
}
