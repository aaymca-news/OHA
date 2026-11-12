<?php

namespace App\Models;

use App\Enums\TaskProgress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One task of a movement's ODP: read from the ODP workbook, or typed in by an assessor
 * when the ODP could not be read. In Stage 2 (ODP follow-up) where it stands is set at
 * each follow-up meeting. A task a revised ODP no longer has is REMOVED (removed_at): kept
 * for the record but out of the plan, so every query of the tasks leaves it out unless it
 * asks for it (withoutGlobalScope(self::IN_PLAN)).
 *
 * @property TaskProgress $progress
 * @property bool $done
 * @property Carbon|null $due_on
 * @property Carbon|null $edited_at
 * @property Carbon|null $reported_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $removed_at
 */
#[Fillable(['assessment_id', 'document_id', 'source', 'ref', 'priority', 'task', 'deadline_text', 'due_on', 'date_guessed', 'responsible', 'done', 'sort_order', 'edited_by', 'edited_at',
    'progress', 'progress_note', 'evidence_id', 'reported_by', 'reported_at', 'confirmed_by', 'confirmed_at', 'returned_reason',
    'carried_from_task_id', 'carried_to_assessment_id', 'dropped_at', 'dropped_by', 'dropped_reason', 'removed_at', 'removed_in_document_id'])]
class OdpTask extends Model
{
    /** The global scope that keeps removed tasks out of the plan. */
    public const IN_PLAN = 'in_plan';

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

    protected static function booted(): void
    {
        static::addGlobalScope(self::IN_PLAN, fn (Builder $q) => $q->whereNull($q->qualifyColumn('removed_at')));
    }

    /** No longer in the revised ODP: kept for the record, out of the plan. */
    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /** Dropped from the follow-up, with a reason, instead of being carried over. */
    public function isDropped(): bool
    {
        return $this->dropped_at !== null;
    }

    /** Nothing more to do on it before the cycle closes: done, carried over, or dropped. */
    public function isSettled(): bool
    {
        return $this->done || $this->carried_to_assessment_id !== null || $this->isDropped() || $this->isRemoved();
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
     * Where it stands, in the site's one status language: the filter it falls under
     * (blocked, progress, done, other), its words and its tone. Its status is set at each
     * follow-up meeting, for every task together (decided 12 Nov 2026); tasks have no
     * deadlines of their own (the follow-ups have dates).
     *
     * @return array{key: string, label: string, tone: string}
     */
    public function followUpStatus(): array
    {
        return match (true) {
            $this->isRemoved() => ['key' => 'other', 'label' => 'Removed in the revised ODP', 'tone' => 'neutral'],
            $this->isDropped() => ['key' => 'other', 'label' => 'Dropped', 'tone' => 'neutral'],
            $this->carried_to_assessment_id !== null => ['key' => 'other', 'label' => 'Carried over', 'tone' => 'info'],
            $this->done => ['key' => 'done', 'label' => 'Done', 'tone' => 'good'],
            $this->progress === TaskProgress::Blocked => ['key' => 'blocked', 'label' => 'Blocked', 'tone' => 'critical'],
            $this->progress === TaskProgress::InProgress => ['key' => 'progress', 'label' => 'In progress', 'tone' => 'warning'],
            default => ['key' => 'progress', 'label' => 'Not started', 'tone' => 'neutral'],
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
            'removed_at' => 'datetime',
        ];
    }
}
