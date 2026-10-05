<?php

namespace App\Models;

use Database\Factories\TimelineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Either a hand-set gate deadline (gate_id set) or a custom step (gate_id NULL).
 * A gate's default deadline and its completion are derived, so only a date a
 * person chose is ever stored here.
 */
#[Fillable(['assessment_id', 'gate_id', 'label', 'due_on', 'done_on', 'created_by', 'updated_by'])]
class TimelineItem extends Model
{
    /** @use HasFactory<TimelineItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Gate, $this>
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_on' => 'date',
        ];
    }
}
