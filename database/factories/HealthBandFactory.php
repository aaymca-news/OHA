<?php

namespace Database\Factories;

use App\Models\HealthBand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The real bands are inserted by the reference-data migration. Adding a band
 * changes how every score is banded, so use this only in tests that mean to.
 *
 * @extends Factory<HealthBand>
 */
class HealthBandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('band-????'),
            'label' => fake()->word(),
            'min_pct' => fake()->unique()->randomFloat(2, 1, 99),
            'color' => fake()->hexColor(),
            'icon' => 'help',
            'reassess_months' => fake()->numberBetween(6, 36),
            'sort_order' => fake()->numberBetween(10, 99),
        ];
    }
}
