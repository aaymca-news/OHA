<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A follow-up meeting on a signed ODP (Stage 2): when it was held, who came, what was
 * said, and every task as it stood then (tasks()).
 *
 * @property int $number
 * @property Carbon $held_on
 */
#[Fillable(['assessment_id', 'number', 'held_on', 'attendees', 'summary', 'recorded_by'])]
class OdpFollowUp extends Model
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

    /**
     * @return HasMany<OdpFollowUpTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OdpFollowUpTask::class)->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'held_on' => 'date',
        ];
    }
}
