<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\FormUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Picks a real weighted category and a points value within its maximum.
 * Pass category_id explicitly when creating several scores for one assessment.
 *
 * @extends Factory<CategoryScore>
 */
class CategoryScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var Category $category */
        $category = Category::query()->weighted()->inRandomOrder()->firstOrFail();

        return [
            'assessment_id' => Assessment::factory(),
            'category_id' => $category->id,
            'points' => fake()->numberBetween(0, (int) $category->max_points),
            'max_points_at_scoring' => $category->max_points,
            'form_upload_id' => FormUpload::factory(),
            'scored_at' => now(),
        ];
    }
}
