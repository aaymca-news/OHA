<?php

namespace Database\Factories;

use App\Models\MembershipStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The real statuses are inserted by the reference-data migration; this makes extra ones for tests.
 *
 * @extends Factory<MembershipStatus>
 */
class MembershipStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('status-????'),
            'label' => fake()->words(2, true),
            'icon' => 'help',
            'sort_order' => fake()->numberBetween(10, 99),
        ];
    }
}
