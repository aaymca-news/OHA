<?php

namespace App\Models;

use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of an assessment's four gate milestones (view v_assessment_milestones):
 * when it is due, when it was cleared, and whether it was late or is overdue.
 * The real key is (assessment_id, gate_id); query it through the assessment.
 *
 * @property int $assessment_id
 * @property string $gate_code
 * @property bool $set_by_hand
 * @property bool $completed_late
 * @property bool $overdue
 */
#[Table(name: 'v_assessment_milestones', key: 'assessment_id', incrementing: false, timestamps: false)]
class AssessmentMilestone extends Model
{
    use IsDatabaseView;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
            'set_by_hand' => 'boolean',
            'completed_late' => 'boolean',
            'overdue' => 'boolean',
        ];
    }
}
