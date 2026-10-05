<?php

namespace Database\Factories;

use App\Enums\ArtefactKind;
use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Models\Artefact;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artefact_id' => Artefact::factory()->kind(ArtefactKind::Report),
            'purpose' => DocumentPurpose::Reference,
            'format' => DocumentFormat::Docx,
            'disk' => 'local',
            'path' => 'oha/documents/'.fake()->uuid().'.docx',
            'original_name' => 'OHA Report.docx',
            'size_bytes' => fake()->numberBetween(20_000, 400_000),
            'sha256' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
