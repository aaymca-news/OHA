<?php

namespace App\Models;

use Database\Factories\HealthBandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A health band. A score belongs to the band with the highest min_pct not above it.
 */
#[Fillable(['code', 'label', 'min_pct', 'color', 'icon', 'reassess_months', 'sort_order'])]
#[WithoutTimestamps]
class HealthBand extends Model
{
    /** @use HasFactory<HealthBandFactory> */
    use HasFactory;

    /**
     * The band a percentage falls in: the highest min_pct not above it. The one place a
     * score is matched to its band (the database views do the same in SQL).
     *
     * $rated: a score that was rated always has a real band, so 0% is the lowest real
     * band, never "Not started" (the drafted report says what band each category is in).
     */
    public static function forPercentage(float $pct, bool $rated = false): self
    {
        $pct = $rated ? max($pct, 0.01) : $pct;

        return self::ordered()
            ->first(fn (self $b) => (float) $b->min_pct <= $pct && (! $rated || (float) $b->min_pct > 0))
            ?? throw (new ModelNotFoundException)->setModel(self::class);
    }

    /**
     * Every band, highest first, read once per request.
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return once(fn () => self::query()->orderByDesc('min_pct')->get());
    }

    protected function casts(): array
    {
        return [
            'min_pct' => 'decimal:2',
            'reassess_months' => 'integer',
        ];
    }
}
