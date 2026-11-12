<?php

namespace App\Models;

use App\Enums\ArtefactKind;
use Closure;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $closed_at when Stage 1 was closed (Baseline 1 set)
 */
#[Fillable(['movement_id', 'period_label', 'assessed_on', 'opened_at', 'opened_by', 'odp_read_document_id', 'odp_read_problem'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    /** Why the OHA form, report and ODP can no longer change: said the same way everywhere. */
    public const FROZEN = 'The assessment is closed, so the OHA form, the report and the ODP are frozen.';

    /**
     * Under way: opened and not yet finished (it has a work item until it is closed).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnderWay(Builder $query): void
    {
        $query->whereHas('workItem');
    }

    /**
     * Done: Stage 1 closed (Baseline 1 set); its follow-ups are under way, or done.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDone(Builder $query): void
    {
        $query->whereNotNull('closed_at');
    }

    /**
     * Only each movement's latest assessment (the newest opened), or its latest among
     * those $among selects (for example, the ones with an ODP). One query, in the database.
     *
     * @param  Builder<self>  $query
     * @param  (Closure(Builder<self>): mixed)|null  $among
     */
    public function scopeLatestPerMovement(Builder $query, ?Closure $among = null): void
    {
        $query->whereIn('id', self::query()->selectRaw('max(id)')->when($among, fn (Builder $q) => $among($q))->groupBy('movement_id'));
    }

    /**
     * The address of an assessment's tab (form, report, odp, timeline, audit). Relative
     * when $absolute is false, as stored in notifications.
     */
    public static function tabUrl(self|int $assessment, ?string $tab = null, bool $absolute = true): string
    {
        $id = $assessment instanceof self ? $assessment->id : $assessment;

        return route('assessments.show', array_filter(['assessment' => $id, 'tab' => $tab]), $absolute);
    }

    /** Whether its ODP follow-up cycle is closed (Stage 2 done): kept as read-only history. */
    public function isFollowUpClosed(): bool
    {
        return $this->follow_up_closed_at !== null;
    }

    /**
     * Who closed the follow-up cycle.
     *
     * @return BelongsTo<User, $this>
     */
    public function followUpCloser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_closed_by');
    }

    /** Whether it is under way (see scopeUnderWay), from the loaded work item if there is one. */
    public function isUnderWay(): bool
    {
        return $this->relationLoaded('workItem') ? $this->workItem !== null : $this->workItem()->exists();
    }

    /**
     * @return BelongsTo<Movement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return HasMany<Artefact, $this>
     */
    public function artefacts(): HasMany
    {
        return $this->hasMany(Artefact::class);
    }

    /**
     * @return HasOne<Artefact, $this>
     */
    public function form(): HasOne
    {
        return $this->hasOne(Artefact::class)->where('kind', ArtefactKind::Form);
    }

    /**
     * @return HasOne<Artefact, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(Artefact::class)->where('kind', ArtefactKind::Report);
    }

    /**
     * @return HasOne<Artefact, $this>
     */
    public function odp(): HasOne
    {
        return $this->hasOne(Artefact::class)->where('kind', ArtefactKind::Odp);
    }

    /**
     * The supporting documents supplied for this assessment.
     *
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasMany<CategoryScore, $this>
     */
    public function categoryScores(): HasMany
    {
        return $this->hasMany(CategoryScore::class);
    }

    /**
     * Its baselines, in force and set aside (superseded), in order.
     *
     * @return HasMany<Baseline, $this>
     */
    public function baselines(): HasMany
    {
        return $this->hasMany(Baseline::class)->orderBy('number')->orderBy('id');
    }

    /**
     * The newest baseline in force: its score is the movement's score everywhere.
     *
     * @return HasOne<Baseline, $this>
     */
    public function currentBaseline(): HasOne
    {
        return $this->hasOne(Baseline::class)->whereNull('superseded_at')->orderByDesc('number');
    }

    /**
     * The derived overall score and band: the newest baseline's (see v_assessment_scores).
     *
     * @return HasOne<AssessmentScore, $this>
     */
    public function score(): HasOne
    {
        return $this->hasOne(AssessmentScore::class);
    }

    /**
     * @return HasOne<WorkItem, $this>
     */
    public function workItem(): HasOne
    {
        return $this->hasOne(WorkItem::class);
    }

    /**
     * @return HasMany<TimelineItem, $this>
     */
    public function timelineItems(): HasMany
    {
        return $this->hasMany(TimelineItem::class);
    }

    /**
     * The ODP version its tasks were last read from.
     *
     * @return BelongsTo<Document, $this>
     */
    public function odpReadDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'odp_read_document_id');
    }

    /**
     * The ODP's tasks and their deadlines, in the ODP's order.
     *
     * @return HasMany<OdpTask, $this>
     */
    public function odpTasks(): HasMany
    {
        return $this->hasMany(OdpTask::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The follow-up meetings recorded once it is closed (Stage 2), first to last.
     *
     * @return HasMany<OdpFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(OdpFollowUp::class)->orderBy('number');
    }

    /**
     * The overall review of its ODP (Stage 2), once written.
     *
     * @return HasOne<OdpReview, $this>
     */
    public function odpReview(): HasOne
    {
        return $this->hasOne(OdpReview::class);
    }

    /** The movement's next assessment after this one (opened later), if there is one yet. */
    public function nextAssessment(): ?self
    {
        return self::query()->where('movement_id', $this->movement_id)->where('id', '>', $this->id)->oldest('id')->first();
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * Once the assessment is closed (Stage 1 done), the OHA form, the report and the ODP
     * are frozen: the ODP rests on the report, which rests on the form. The Board
     * Chairperson's signature, now optional, freezes nothing.
     */
    public function isFrozen(): bool
    {
        return $this->isClosed();
    }

    /** Whether Stage 1 is closed: Baseline 1 set, its follow-up under way (or done). */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Who closed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'follow_up_closed_at' => 'datetime',
        ];
    }
}
