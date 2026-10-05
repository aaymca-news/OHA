<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Gate;
use App\Models\TimelineItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A custom step by default; use gateDeadline() for a hand-set gate deadline.
 *
 * @extends Factory<TimelineItem>
 */
class TimelineItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'gate_id' => null,
            'label' => fake()->randomElement(['Form sent to the movement', 'Field visit', 'Debrief with the board']),
            'due_on' => now()->addDays(14)->toDateString(),
            'done_on' => null,
            'created_by' => User::factory(),
        ];
    }

    public function gateDeadline(string $gateCode, string $dueOn): static
    {
        return $this->state(fn (array $attributes) => [
            'gate_id' => Gate::query()->where('code', $gateCode)->value('id'),
            'label' => null,
            'due_on' => $dueOn,
            'done_on' => null,
        ]);
    }
}
