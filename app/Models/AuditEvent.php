<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the append-only audit trail. The database rejects any update or
 * delete of an existing row.
 */
#[Fillable(['occurred_at', 'actor_id', 'action', 'subject_type', 'subject_id', 'assessment_id', 'from_state', 'to_state', 'routed_to', 'payload', 'ip'])]
#[WithoutTimestamps]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
