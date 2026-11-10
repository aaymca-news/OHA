<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The annual review of a signed ODP (Stage 2), written before the next assessment: what
 * was achieved, what was not and why, and the lessons, with the task counts then.
 */
#[Fillable(['assessment_id', 'achieved', 'not_achieved', 'lessons', 'tasks_done', 'tasks_total', 'recorded_by'])]
class OdpReview extends Model
{
    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'tasks_done' => 'integer',
            'tasks_total' => 'integer',
        ];
    }
}
