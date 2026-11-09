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

#[Fillable(['movement_id', 'period_label', 'assessed_on', 'opened_at', 'opened_by', 'odp_read_document_id', 'odp_read_problem'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    /**
     * Under way: opened and not yet finished (it has a work item until its ODP is signed).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnderWay(Builder $query): void
    {
        $query->whereHas('workItem');
    }

    /**
     * Done: Stage 1 finished, its ODP signed by the board; its follow-ups are under way.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDone(Builder $query): void
    {
        $query->whereDoesntHave('workItem')->whereHas('odp', fn (Builder $odp) => $odp->whereHas('signature'));
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
     * The derived overall score and band.
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
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * Once the Board Chairperson has signed the ODP, Stage 2 begins and the OHA form, the
     * report and the ODP are frozen: the ODP rests on the report, which rests on the form.
     */
    public function isFrozen(): bool
    {
        return BoardSignature::query()->whereHas('artefact', fn ($q) => $q
            ->where('assessment_id', $this->id)->where('kind', ArtefactKind::Odp))->exists();
    }

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'opened_at' => 'datetime',
        ];
    }
}
