<?php

namespace Database\Factories;

use App\Enums\DqaDimension;
use App\Enums\FindingSeverity;
use App\Models\Category;
use App\Models\FormFinding;
use App\Models\FormUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormFinding>
 */
class FormFindingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_upload_id' => FormUpload::factory(),
            'severity' => FindingSeverity::Missing,
            'rule' => 'unanswered',
            'ref' => fn (array $attributes) => 'q:'.$attributes['question_code'],
            'question_code' => 'Q'.fake()->numberBetween(201, 248),
            'category_id' => fn () => Category::query()->where('code', 'financial')->value('id'),
            'location' => '2 Financial Stability',
            'message' => 'The question is unanswered.',
            'hint' => 'Not answered.',
            'points_at_stake' => 0,
            'dqa_dimension' => DqaDimension::Completeness,
        ];
    }
}
