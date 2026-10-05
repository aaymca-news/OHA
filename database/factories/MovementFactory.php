<?php

namespace Database\Factories;

use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Movement>
 */
class MovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = fake()->unique()->country();
        $suffix = fake()->unique()->numberBetween(100, 999);

        // The suffix keeps test movements apart from the 23 real ones.
        return [
            'slug' => Str::limit(Str::slug($country), 30, '').'-'.$suffix,
            'name' => $country.' YMCA '.$suffix,
            'country' => $country,
            'city' => fake()->city(),
            'zone_id' => fn () => Zone::query()->inRandomOrder()->value('id'),
            'membership_status_id' => fn () => MembershipStatus::query()->where('code', 'chartered')->value('id'),
            'planned_assessment_label' => null,
            'planned_assessment_on' => null,
        ];
    }

    public function plannedFor(string $label, ?string $firstDay): static
    {
        return $this->state(fn (array $attributes) => [
            'planned_assessment_label' => $label,
            'planned_assessment_on' => $firstDay,
        ]);
    }
}
