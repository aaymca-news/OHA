<?php

namespace App\Models;

use App\Models\Concerns\IsDatabaseView;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A movement's current standing (view v_movement_status): latest score and band,
 * when it is next due for assessment, and whether an ODP exists.
 *
 * @property int $movement_id
 * @property string $name
 * @property int|null $latest_assessment_id
 * @property string|null $pct
 * @property string $band_code
 * @property bool $reassessment_overdue
 * @property bool $has_odp
 */
#[Table(name: 'v_movement_status', key: 'movement_id', incrementing: false, timestamps: false)]
class MovementStatus extends Model
{
    use IsDatabaseView;

    /**
     * @return BelongsTo<Movement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
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
            'last_assessed_on' => 'date',
            'next_assessment_on' => 'date',
            'points_achieved' => 'decimal:2',
            'points_available' => 'integer',
            'pct' => 'decimal:1',
            'reassess_months' => 'integer',
            'reassessment_overdue' => 'boolean',
            'has_odp' => 'boolean',
        ];
    }
}
