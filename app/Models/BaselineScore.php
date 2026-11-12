<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A baseline's points for one weighted category, with the maximum that applied then.
 * Written once, when the baseline is recorded; the database rejects any UPDATE. The real
 * key is (baseline_id, category_id).
 *
 * @property int $baseline_id
 * @property int $category_id
 * @property string $points
 * @property int $max_points_at_scoring
 */
#[Fillable(['baseline_id', 'category_id', 'points', 'max_points_at_scoring'])]
#[Table(key: 'baseline_id', incrementing: false, timestamps: false)]
class BaselineScore extends Model
{
    /**
     * @return BelongsTo<Baseline, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'max_points_at_scoring' => 'integer',
        ];
    }
}
