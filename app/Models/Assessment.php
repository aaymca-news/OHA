<?php

namespace App\Models;

use App\Enums\ArtefactKind;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['movement_id', 'period_label', 'assessed_on', 'opened_at', 'opened_by'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

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
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'opened_at' => 'datetime',
        ];
    }
}
