<?php

namespace App\Oha;

use App\Models\Category;

/**
 * Recomputes each category's points from the answers alone, using the form's
 * own formulas. Scores never depend on the totals cached in the workbook, so the
 * stored answers are enough to score a form again at any time.
 */
final class Scorer
{
    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $missingSheets  category codes whose sheet was absent (they score 0)
     * @return array<string, int|null> category code => points, or null when the category is not weighted
     */
    public function score(array $answers, array $missingSheets = []): array
    {
        $weighted = Category::query()->pluck('max_points', 'code');
        $points = [];

        foreach (FormDefinition::categories() as $code => $category) {
            if (($weighted[$code] ?? null) === null) {
                $points[$code] = null;

                continue;
            }

            $points[$code] = in_array($code, $missingSheets, true)
                ? 0
                : array_sum(array_map(fn (Question $q) => $q->score($answers), $category['questions']));
        }

        return $points;
    }
}
