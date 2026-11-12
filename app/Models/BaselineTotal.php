<?php

namespace App\Models;

use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A baseline's overall points, percentage and band (view v_baseline_scores), derived as
 * v_assessment_scores derives an assessment's.
 *
 * @property int $baseline_id
 * @property int $assessment_id
 * @property int $number
 * @property string $points_achieved
 * @property int $points_available
 * @property string $pct
 * @property string $band_code
 */
#[Table(name: 'v_baseline_scores', key: 'baseline_id', incrementing: false, timestamps: false)]
class BaselineTotal extends Model
{
    use IsDatabaseView;

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
            'number' => 'integer',
            'points_achieved' => 'decimal:2',
            'points_available' => 'integer',
            'pct' => 'decimal:1',
        ];
    }
}
