<?php

namespace App\Oha;

use App\Models\Category;

/**
 * Every point a set of answers does not yet earn, read from the form's own scoring
 * (FormDefinition's closures, which mirror the Excel formulas), so the site and the Excel
 * form never disagree: once each gap is met, the answers score the full maximum of every
 * weighted category (84 of 84 on Version 1.0 - 2026).
 *
 * Each scored question is one item: its points now and at most, the answers that decide
 * it (its "drivers"; most read only their own answer), and what earns the points, in the
 * form's terms. "Not Applicable" scores 0 with the maximum unchanged, exactly as the
 * Excel form scores it (decided 12 Nov 2026).
 */
final class ScoreGaps
{
    /** Scored questions whose points are decided by answers other than (or besides) their own. */
    private const DRIVERS = [
        'Q214' => FormDefinition::INCOME,
        'Q223' => ['Q224'],
        'Q244' => ['Q244', 'Q245'],
    ];

    /**
     * A short name for each scored question that evidence does not prove (those are named
     * in EvidenceQuestions::LABELS), for lists such as the dashboard's "most often missed".
     */
    private const NAMES = [
        'Q206' => 'Auditor engaged 1–5 years',
        'Q214' => 'Spread of income sources',
        'Q223' => 'International funding within the limit',
        'Q225' => 'Restricted funding below 85%',
        'Q227' => 'Would cope without a major funder',
        'Q238' => 'Expenses covered for 2 years',
        'Q241' => 'Reserve lasting over 6 months',
        'Q242' => 'No outstanding debt',
        'Q244' => 'No unaffordable interest-rate risk',
        'Q320' => 'Board recruited on skills',
        'Q322' => 'Board term under 5 years',
        'Q323' => 'Term limit for board members',
        'Q325' => 'Term limit for the Chair',
        'Q326#2' => 'Board met twice or more',
        'Q328' => 'CEO/GS and Chair are different people',
        'Q511' => 'Project-based monitoring',
        'Q513' => 'Project-based needs assessment',
        'Q612' => 'Plan made with staff',
        'Q613' => 'Plan made with the Board',
        'Q614' => 'Plan made with members',
        'Q615' => 'Plan made with volunteers',
        'Q616' => 'Plan made with young people and communities',
        'Q619' => 'Innovation in the Strategic Plan',
        'Q630' => 'Mission in line with YMCA values',
        'Q813' => 'Communications staff or volunteer',
        'Q1001' => 'Enough volunteers',
        'Q1002' => 'Enough staff',
    ];

    /** What earns the points where the form's rule is a threshold, or reads several answers. */
    private const RULES = [
        'Q214' => 'Income by source (Q214–Q222): 2 points when no single source is 50% or more of the income; 1 point when the largest is 50% to 65%; 0 when one is above 65%.',
        'Q223' => '1 point when the share from international sources (Q224) is '.FormDefinition::INTERNATIONAL_LIMIT.'% or less. The form scores this row from Q224.',
        'Q225' => '1 point when restricted funding is below 85%.',
        'Q241' => '1 point when the reserve can be used for more than 6 months.',
        'Q244' => '1 point only when Q244 and Q245 are both answered “No”.',
        'Q322' => '1 point when the standard term is below 5 years.',
    ];

    /**
     * Every scored question, in the form's order, by weighted category.
     *
     * @return array<string, list<array{code: string, question: Question, max: int, drivers: list<string>, earns: string, options: list<string>|null}>>
     */
    public static function scored(): array
    {
        $weighted = Category::query()->weighted()->pluck('code')->all();
        $scored = [];
        foreach (FormDefinition::categories() as $category => $definition) {
            if (! in_array($category, $weighted, true)) {
                continue;
            }
            foreach ($definition['questions'] as $q) {
                if (($q->max ?? 0) <= 0 || $q->points === null) {
                    continue;
                }
                $options = self::earningOptions($q);
                $scored[$category][] = [
                    'code' => $q->code,
                    'question' => $q,
                    'max' => (int) $q->max,
                    'drivers' => self::DRIVERS[$q->code] ?? [$q->code],
                    'earns' => self::RULES[$q->code] ?? self::describe($q, $options),
                    'options' => $options,
                ];
            }
        }

        return $scored;
    }

    /**
     * The points these answers do not yet earn, by weighted category: each scored question
     * below its maximum, with its points now. Empty when every point is earned.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, list<array{code: string, question: Question, max: int, points: int, drivers: list<string>, earns: string, options: list<string>|null}>>
     */
    public static function for(array $answers): array
    {
        $gaps = [];
        foreach (self::scored() as $category => $items) {
            foreach ($items as $item) {
                $points = $item['question']->score($answers);
                if ($points < $item['max']) {
                    $gaps[$category][] = $item + ['points' => $points];
                }
            }
        }

        return $gaps;
    }

    /**
     * Points not yet earned, by weighted category.
     *
     * @param  array<string, list<array{max: int, points: int}>>  $gaps
     * @return array<string, int>
     */
    public static function pointsOpen(array $gaps): array
    {
        return array_map(fn (array $items) => array_sum(array_map(fn (array $i) => $i['max'] - $i['points'], $items)), $gaps);
    }

    /** A scored question's short name, e.g. "Cash reserve" (Q239); its code when it has none. */
    public static function name(string $code): string
    {
        return EvidenceQuestions::label($code) ?? self::NAMES[$code] ?? $code;
    }

    /**
     * For a choice question scored on its own answer: the options that earn its maximum.
     * Null for any other question (a figure, or a rule over several answers).
     *
     * @return list<string>|null
     */
    public static function earningOptions(Question $q): ?array
    {
        if ($q->type !== 'choice' || $q->options === null || isset(self::DRIVERS[$q->code]) || isset(self::RULES[$q->code])) {
            return null;
        }

        return array_values(array_filter($q->options, fn (string $option) => $q->score([$q->code => $option]) === $q->max));
    }

    /**
     * @param  list<string>|null  $options
     */
    private static function describe(Question $q, ?array $options): string
    {
        $points = $q->max.' '.($q->max === 1 ? 'point' : 'points');
        if ($options === null || $options === []) {
            return $points.', by the form’s scoring.';
        }
        $others = array_values(array_diff($q->options ?? [], $options));

        return $points.' for “'.implode('” or “', $options).'”'
            .($others !== [] ? '; “'.implode('”, “', $others).'” '.(count($others) === 1 ? 'scores' : 'score').' 0, as on the form.' : '.');
    }
}
