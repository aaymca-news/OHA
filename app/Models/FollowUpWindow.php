<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One follow-up of a closed assessment (see App\Oha\FollowUpSchedule for its state): the
 * date agreed for it, when it was opened, closed and locked, and reopened.
 *
 * @property int $number
 * @property Carbon|null $due_on
 * @property Carbon|null $opened_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $reopened_at
 * @property array<string, mixed>|null $answers while open: the answers re-scored for its baseline (null: unchanged)
 * @property array<string, array{reason: string, by: int|null, by_name: string, at: string, via: string, file?: string}>|null $answer_changes while open: why each answer changed (what it was and is are read from the answers)
 */
#[Fillable(['assessment_id', 'number'])]
class FollowUpWindow extends Model
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
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * The ODP version in force when it was closed.
     *
     * @return BelongsTo<Document, $this>
     */
    public function odpDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'odp_document_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_on' => 'date',
            'opened_at' => 'datetime',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
            'answers' => 'array',
            'answer_changes' => 'array',
        ];
    }
}
