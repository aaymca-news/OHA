<?php

namespace App\Support;

use App\Oha\FormDefinition;

/**
 * Red flags (decided 23 Nov 2026, thresholds agreed the same day): early warnings that a
 * movement may need support. Each check is true (flagged), false (clear) or null (cannot be
 * told yet, e.g. no follow-up score to compare). The words are in App\Support\Kpis.
 */
final class RedFlags
{
    /** A cash reserve under this many months is flagged (none at all too). */
    public const RESERVE_MONTHS = 3;

    /** This share (%) or more of the provable "Yes" answers without evidence is flagged. */
    public const UNPROVEN_SHARE = 25;

    /** @var array<string, array{short: string, full: string}> */
    /** Each check: a one-word heading for the table (short), and what it means in full. */
    public const CHECKS = [
        'no_gain' => ['short' => 'No gain', 'full' => 'No gain at Follow-up 1'],
        'international' => ['short' => 'Funding', 'full' => 'International funding above '.FormDefinition::INTERNATIONAL_LIMIT.'% of income'],
        'reserve' => ['short' => 'Reserve', 'full' => 'No cash reserve, or one under '.self::RESERVE_MONTHS.' months'],
        'deficit' => ['short' => 'Deficit', 'full' => 'An operating deficit in the last financial year'],
        'unproven' => ['short' => 'Evidence', 'full' => self::UNPROVEN_SHARE.'% or more of its provable “Yes” answers without evidence'],
    ];

    /**
     * One movement's checks.
     *
     * @param  list<float>  $scores  the baseline, then each follow-up score (empty: no baseline yet)
     * @param  array<string, mixed>  $profile  from App\Oha\Profile
     * @param  int  $provableYes  its "Yes" answers a document can prove
     * @param  int|null  $unproven  of those, how many have no evidence (null: no baseline yet)
     * @return array<string, bool|null>
     */
    public static function of(array $scores, array $profile, int $provableYes, ?int $unproven): array
    {
        $international = $profile['international_pct'] ?? null;
        $margin = $profile['finance']['margin_pct'] ?? null;

        return [
            'no_gain' => count($scores) > 1 ? $scores[1] <= $scores[0] : null,
            'international' => $international === null ? null : (float) $international > FormDefinition::INTERNATIONAL_LIMIT,
            'reserve' => ($profile['finance']['reserve_months'] ?? null) === null || (float) $profile['finance']['reserve_months'] < self::RESERVE_MONTHS,
            'deficit' => $margin === null ? null : (float) $margin < 0,
            'unproven' => $unproven === null || $provableYes === 0 ? null : $unproven * 100 / $provableYes >= self::UNPROVEN_SHARE,
        ];
    }
}
