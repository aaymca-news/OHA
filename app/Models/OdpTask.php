<?php

namespace App\Models;

use App\Enums\TaskProgress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One task of a movement's ODP and its deadline: read from the ODP workbook, or typed
 * in by an assessor when the ODP could not be read. In Stage 2 (ODP follow-up) its
 * progress is reported by the movement's General Secretary or its assessors; a Done is
 * counted ($done) once an assessor has confirmed it.
 *
 * @property TaskProgress $progress
 * @property bool $done
 * @property Carbon|null $due_on
 * @property Carbon|null $edited_at
 * @property Carbon|null $reported_at
 * @property Carbon|null $confirmed_at
 */
#[Fillable(['assessment_id', 'document_id', 'source', 'ref', 'priority', 'task', 'deadline_text', 'due_on', 'date_guessed', 'responsible', 'done', 'sort_order', 'edited_by', 'edited_at',
    'progress', 'progress_note', 'evidence_id', 'reported_by', 'reported_at', 'confirmed_by', 'confirmed_at', 'returned_reason',
    'carried_from_task_id', 'carried_to_assessment_id', 'dropped_at', 'dropped_by', 'dropped_reason'])]
class OdpTask extends Model
{
    public const FROM_ODP = 'odp';

    public const TYPED = 'typed';

    /** An unfinished task of an earlier ODP, carried into this assessment (Stage 2, step 5). */
    public const CARRIED = 'carried';

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /**
     * The unfinished task of an earlier ODP this one continues, when carried over.
     *
     * @return BelongsTo<OdpTask, $this>
     */
    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(OdpTask::class, 'carried_from_task_id');
    }

    /**
     * The later assessment this unfinished task was carried into.
     *
     * @return BelongsTo<Assessment, $this>
     */
    public function carriedTo(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'carried_to_assessment_id');
    }

    /** Dropped from the follow-up, with a reason, instead of being carried over. */
    public function isDropped(): bool
    {
        return $this->dropped_at !== null;
    }

    /** Nothing more to do on it before the cycle closes: done, carried over, or dropped. */
    public function isSettled(): bool
    {
        return $this->done || $this->carried_to_assessment_id !== null || $this->isDropped();
    }

    /**
     * Who dropped it from the follow-up.
     *
     * @return BelongsTo<User, $this>
     */
    public function dropper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dropped_by');
    }

    /**
     * Where it stands on the follow-up page, in the site's one status language: the filter
     * it falls under (overdue, waiting, progress, done, other), its words and its tone.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public function followUpStatus(): array
    {
        return match (true) {
            $this->isDropped() => ['key' => 'other', 'label' => 'Dropped', 'tone' => 'neutral'],
            $this->carried_to_assessment_id !== null => ['key' => 'other', 'label' => 'Carried over', 'tone' => 'info'],
            $this->done => ['key' => 'done', 'label' => 'Done', 'tone' => 'good'],
            $this->awaitsConfirmation() => ['key' => 'waiting', 'label' => 'Waiting to confirm', 'tone' => 'info'],
            $this->state() === 'overdue' => ['key' => 'overdue', 'label' => 'Overdue', 'tone' => 'critical'],
            $this->progress === TaskProgress::Blocked => ['key' => 'overdue', 'label' => 'Blocked', 'tone' => 'critical'],
            $this->progress === TaskProgress::InProgress => ['key' => 'progress', 'label' => 'In progress', 'tone' => 'warning'],
            default => ['key' => 'progress', 'label' => 'Not started', 'tone' => 'neutral'],
        };
    }

    /** Reported Done, and waiting for an assessor to confirm it. */
    public function awaitsConfirmation(): bool
    {
        return $this->progress === TaskProgress::Done && ! $this->done;
    }

    /** done, overdue, soon (within config oha.due_soon_days.odp_tasks, 30 days), upcoming, or undated (no date could be read or was given). */
    public function state(): string
    {
        return match (true) {
            $this->done => 'done',
            $this->due_on === null => 'undated',
            $this->due_on->lt(today()) => 'overdue',
            $this->due_on->lte(today()->addDays(config('oha.due_soon_days.odp_tasks'))) => 'soon',
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
            'progress' => TaskProgress::class,
            'reported_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dropped_at' => 'datetime',
        ];
    }
}
