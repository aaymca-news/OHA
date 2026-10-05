<?php

namespace Database\Factories;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Models\Artefact;
use App\Models\Assessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artefact>
 */
class ArtefactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'kind' => ArtefactKind::Form,
            'state' => ArtefactState::NotStarted,
        ];
    }

    public function kind(ArtefactKind $kind): static
    {
        return $this->state(fn (array $attributes) => ['kind' => $kind]);
    }

    public function inState(ArtefactState $state): static
    {
        return $this->state(fn (array $attributes) => ['state' => $state]);
    }
}
