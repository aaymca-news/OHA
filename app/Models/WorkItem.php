<?php

namespace App\Models;

use App\Enums\ArtefactKind;
use App\Enums\HolderRole;
use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open assessment: who holds it and when they must act (view v_work_items).
 *
 * @property int $assessment_id
 * @property int $artefact_id
 * @property ArtefactKind $artefact_kind
 * @property HolderRole $holder_role
 * @property int $days_left
 * @property bool $overdue
 * @property bool $gate_due_set_by_hand
 */
#[Table(name: 'v_work_items', key: 'assessment_id', incrementing: false, timestamps: false)]
class WorkItem extends Model
{
    use IsDatabaseView;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Artefact, $this>
     */
    public function artefact(): BelongsTo
    {
        return $this->belongsTo(Artefact::class);
    }

    protected function casts(): array
    {
        return [
            'artefact_kind' => ArtefactKind::class,
            'holder_role' => HolderRole::class,
            'holder_since' => 'datetime',
            'gate_due_on' => 'date',
            'turnaround_due_on' => 'date',
            'due_on' => 'date',
            'days_left' => 'integer',
            'overdue' => 'boolean',
            'gate_due_set_by_hand' => 'boolean',
        ];
    }
}
