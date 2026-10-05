<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'actor_id' => User::factory(),
            'action' => 'assessment.opened',
            'subject_type' => 'assessment',
            'subject_id' => fn (array $attributes) => $attributes['assessment_id'],
            'assessment_id' => Assessment::factory(),
            'payload' => null,
        ];
    }
}
