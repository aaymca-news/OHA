<?php

namespace App\Models;

use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overall points, percentage and band of an assessment (view v_assessment_scores).
 *
 * @property int $assessment_id
 * @property int $movement_id
 * @property string $points_achieved
 * @property int $points_available
 * @property string $pct
 * @property string $band_code
 */
#[Table(name: 'v_assessment_scores', key: 'assessment_id', incrementing: false, timestamps: false)]
class AssessmentScore extends Model
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
     * @return BelongsTo<HealthBand, $this>
     */
    public function band(): BelongsTo
    {
        return $this->belongsTo(HealthBand::class, 'band_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'points_achieved' => 'decimal:2',
            'points_available' => 'integer',
            'pct' => 'decimal:1',
        ];
    }
}
