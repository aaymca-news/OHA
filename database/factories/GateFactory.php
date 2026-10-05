<?php

namespace Database\Factories;

use App\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The real gates are inserted by the reference-data migration; this makes extra ones for tests.
 *
 * @extends Factory<Gate>
 */
class GateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('gate-????'),
            'name' => fake()->words(2, true),
            'milestone_label' => fake()->sentence(3),
            'sort_order' => fake()->unique()->numberBetween(100, 999),
            'sla_days' => fake()->numberBetween(7, 120),
        ];
    }
}
