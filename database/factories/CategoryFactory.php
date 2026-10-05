<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The real categories are inserted by the reference-data migration; this makes extra ones for tests.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('cat-????'),
            'name' => fake()->unique()->words(3, true),
            'short_name' => fake()->word(),
            'icon' => 'category',
            'form_order' => fake()->unique()->numberBetween(100, 999),
            'max_points' => fake()->numberBetween(1, 20),
        ];
    }

    public function unweighted(): static
    {
        return $this->state(fn (array $attributes) => ['max_points' => null]);
    }
}
