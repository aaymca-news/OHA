<?php

namespace Database\Factories;

use App\Enums\ArtefactKind;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    /**
     * A form-based assessment opened today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $month = now()->startOfMonth();

        return [
            'movement_id' => Movement::factory(),
            'period_label' => $month->format('M Y'),
            'assessed_on' => $month->toDateString(),
            'opened_at' => now(),
            'opened_by' => User::factory(),
        ];
    }

    /**
     * Give the assessment its form, report and ODP, all not started.
     */
    public function withArtefacts(): static
    {
        return $this->afterCreating(function (Assessment $assessment): void {
            foreach (ArtefactKind::cases() as $kind) {
                Artefact::factory()->for($assessment)->create(['kind' => $kind]);
            }
        });
    }
}
