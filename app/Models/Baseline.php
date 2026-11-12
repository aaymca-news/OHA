<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One of an assessment's baselines (decided 12 Nov 2026): Baseline 1, the approved form
 * when the assessment was closed; Baselines 2–4, recorded as follow-ups 1–3 are closed.
 * It keeps the answers it was scored from, its points per category (baseline_scores),
 * what changed since the one before and why, the "Yes" answers still without evidence,
 * and the ODP's progress then. Never edited (the database refuses): when an Administrator
 * reopens its follow-up it is set aside (superseded), kept, and a new one is recorded.
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $number
 * @property int|null $follow_up_number
 * @property array<string, mixed> $answers
 * @property array<string, array{from: mixed, to: mixed, reason: string, by: int|null, by_name: string, at: string, via: string, file?: string}>|null $changes
 * @property list<string>|null $evidence_gaps
 * @property int|null $tasks_done
 * @property int|null $tasks_total
 * @property int $evidence_count
 * @property int|null $odp_document_id
 * @property int|null $form_upload_id
 * @property Carbon $set_at
 * @property int|null $set_by
 * @property Carbon|null $superseded_at
 * @property string|null $superseded_reason
 */
#[Fillable(['assessment_id', 'number', 'follow_up_number', 'answers', 'changes', 'evidence_gaps', 'tasks_done', 'tasks_total', 'evidence_count', 'odp_document_id', 'form_upload_id', 'set_at', 'set_by'])]
#[WithoutTimestamps]
class Baseline extends Model
{
    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * Its points per weighted category.
     *
     * @return HasMany<BaselineScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(BaselineScore::class);
    }

    /**
     * Its overall points, percentage and band (view v_baseline_scores).
     *
     * @return HasOne<BaselineTotal, $this>
     */
    public function total(): HasOne
    {
        return $this->hasOne(BaselineTotal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function superseder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    /**
     * The ODP version in force when it was recorded.
     *
     * @return BelongsTo<Document, $this>
     */
    public function odpDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'odp_document_id');
    }

    /**
     * The answers of each assessment's newest baseline in force, by assessment id; an
     * assessment without one is left out.
     *
     * @param  list<int|null>  $assessmentIds
     * @return array<int, array<string, mixed>>
     */
    public static function newestAnswers(array $assessmentIds): array
    {
        return self::query()->whereIn('assessment_id', array_filter($assessmentIds))->whereNull('superseded_at')
            ->orderBy('number')->get(['assessment_id', 'answers'])
            ->mapWithKeys(fn (self $b) => [$b->assessment_id => $b->answers])->all();
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** "Baseline 2 · after follow-up 1". */
    public function label(): string
    {
        return 'Baseline '.$this->number.($this->follow_up_number !== null ? ' · after follow-up '.$this->follow_up_number : ' · the assessment');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'follow_up_number' => 'integer',
            'answers' => 'array',
            'changes' => 'array',
            'evidence_gaps' => 'array',
            'tasks_done' => 'integer',
            'tasks_total' => 'integer',
            'evidence_count' => 'integer',
            'set_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
