<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes to the append-only audit trail. Called inside the same transaction as
 * the change it records, so a change and its record succeed or fail together.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        ?User $actor,
        string $action,
        Model $subject,
        ?Assessment $assessment = null,
        BackedEnum|string|null $from = null,
        BackedEnum|string|null $to = null,
        ?User $routedTo = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'assessment_id' => $assessment?->id,
            'from_state' => $from instanceof BackedEnum ? $from->value : $from,
            'to_state' => $to instanceof BackedEnum ? $to->value : $to,
            'routed_to' => $routedTo?->id,
            'payload' => $payload === [] ? null : $payload,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
