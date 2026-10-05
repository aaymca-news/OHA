<?php

namespace App\Models;

use Database\Factories\HealthBandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A health band. A score belongs to the band with the highest min_pct not above it.
 */
#[Fillable(['code', 'label', 'min_pct', 'color', 'icon', 'reassess_months', 'sort_order'])]
#[WithoutTimestamps]
class HealthBand extends Model
{
    /** @use HasFactory<HealthBandFactory> */
    use HasFactory;

    /** The band a percentage falls in: the highest min_pct not above it. */
    public static function forPercentage(float $pct): self
    {
        return self::query()->where('min_pct', '<=', $pct)->orderByDesc('min_pct')->firstOrFail();
    }

    protected function casts(): array
    {
        return [
            'min_pct' => 'decimal:2',
            'reassess_months' => 'integer',
        ];
    }
}
