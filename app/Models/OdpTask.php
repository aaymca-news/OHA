<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One task of a movement's ODP and its deadline: read from the ODP workbook, or typed
 * in by an assessor when the ODP could not be read.
 *
 * @property Carbon|null $due_on
 * @property Carbon|null $edited_at
 */
#[Fillable(['assessment_id', 'document_id', 'source', 'ref', 'priority', 'task', 'deadline_text', 'due_on', 'date_guessed', 'responsible', 'done', 'sort_order', 'edited_by', 'edited_at'])]
class OdpTask extends Model
{
    public const FROM_ODP = 'odp';

    public const TYPED = 'typed';

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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** done, overdue, soon (within 30 days), upcoming, or undated (no date could be read or was given). */
    public function state(): string
    {
        return match (true) {
            $this->done => 'done',
            $this->due_on === null => 'undated',
            $this->due_on->lt(today()) => 'overdue',
            $this->due_on->lte(today()->addDays(30)) => 'soon',
            default => 'upcoming',
        };
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done' => 'boolean',
            'date_guessed' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }
}
