<?php

namespace Database\Factories;

use App\Models\Artefact;
use App\Models\FormUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormUpload>
 */
class FormUploadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artefact_id' => Artefact::factory(),
            'disk' => 'local',
            'path' => 'oha-forms/'.fake()->uuid().'.xlsx',
            'original_name' => 'OHA Form.xlsx',
            'size_bytes' => fake()->numberBetween(50_000, 400_000),
            'sha256' => hash('sha256', fake()->uuid()),
            'uploaded_by' => User::factory(),
            'uploaded_at' => now(),
            'answers' => ['Q101' => 'Yes'],
            'form_meta' => [],
            'printed_totals' => null,
        ];
    }
}
