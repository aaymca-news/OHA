<?php

namespace App\Oha;

use App\Models\Assessment;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\HealthBand;
use Illuminate\Support\Collection;

/**
 * An assessment's score per category, in form order: frozen points and maximum,
 * percentage, points lost and band. Used by the score table on screen and by the
 * report and ODP, so both show the same figures.
 */
final class CategoryRows
{
    /**
     * @return list<array{code: string, name: string, points: float|null, max: int|null, pct: float|null, lost: float|null, band: string|null, band_code: string|null, band_icon: string|null}>
     */
    public static function for(Assessment $assessment): array
    {
        /** @var Collection<int, CategoryScore> $scores */
        $scores = $assessment->categoryScores()->get()->keyBy('category_id');
        $bands = HealthBand::query()->orderByDesc('min_pct')->get();

        return Category::query()->orderBy('form_order')->get()->map(function (Category $category) use ($scores, $bands) {
            $score = $scores->get($category->id);

            // Property Management (not weighted) has no score row.
            if ($score === null) {
                return ['code' => $category->code, 'name' => $category->name, 'points' => null, 'max' => null, 'pct' => null, 'lost' => null, 'band' => null, 'band_code' => null, 'band_icon' => null];
            }

            $points = (float) $score->points;
            $max = $score->max_points_at_scoring;
            $pct = round($points * 100 / $max, 1);
            $band = $bands->first(fn (HealthBand $b) => (float) $b->min_pct <= $pct);

            return [
                'code' => $category->code,
                'name' => $category->name,
                'points' => $points,
                'max' => $max,
                'pct' => $pct,
                'lost' => $max - $points,
                'band' => $band?->label,
                'band_code' => $band?->code,
                'band_icon' => $band?->icon,
            ];
        })->values()->all();
    }
}
