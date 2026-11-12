<?php

namespace App\Oha;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\Category;
use App\Models\HealthBand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An assessment's score per category, in form order: frozen points and maximum,
 * percentage, points lost and band. Used by the score table on screen and by the
 * report and ODP, so both show the same figures. By default its score as it stands: its
 * newest baseline (or, before it is closed, its approved form); or a given baseline's.
 */
final class CategoryRows
{
    /**
     * @return list<array{code: string, name: string, points: float|null, max: int|null, pct: float|null, lost: float|null, band: string|null, band_code: string|null, band_icon: string|null}>
     */
    public static function for(Assessment $assessment, ?Baseline $baseline = null): array
    {
        /** @var Collection<int, object{category_id: int, points: string, max_points_at_scoring: int}> $scores */
        $scores = ($baseline !== null
            ? DB::table('baseline_scores')->where('baseline_id', $baseline->id)
            : DB::table('v_current_category_scores')->where('assessment_id', $assessment->id))
            ->get(['category_id', 'points', 'max_points_at_scoring'])->keyBy('category_id');

        return Category::query()->orderBy('form_order')->get()->map(function (Category $category) use ($scores) {
            $score = $scores->get($category->id);

            // Property Management (not weighted) has no score row.
            if ($score === null) {
                return ['code' => $category->code, 'name' => $category->name, 'points' => null, 'max' => null, 'pct' => null, 'lost' => null, 'band' => null, 'band_code' => null, 'band_icon' => null];
            }

            $points = (float) $score->points;
            $max = (int) $score->max_points_at_scoring;
            $pct = round($points * 100 / $max, 1);
            $band = HealthBand::forPercentage($pct);

            return [
                'code' => $category->code,
                'name' => $category->name,
                'points' => $points,
                'max' => $max,
                'pct' => $pct,
                'lost' => $max - $points,
                'band' => $band->label,
                'band_code' => $band->code,
                'band_icon' => $band->icon,
            ];
        })->values()->all();
    }
}
