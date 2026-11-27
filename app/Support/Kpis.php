<?php

namespace App\Support;

use App\Oha\FormDefinition;
use InvalidArgumentException;

/**
 * The KPI dictionary (decided 23 Nov 2026): one definition for every figure the two
 * dashboards show, so each figure means one thing everywhere. The same words fill the ⓘ
 * beside a figure and the folded "How these figures are worked out" at the foot of each
 * dashboard. Each entry: its name, what it means (one sentence), how it is worked out,
 * and, where it matters, what to keep in mind when reading it.
 */
final class Kpis
{
    /** @var array<string, array{label: string, means: string, how: string, mind?: string}> */
    private const DEFINITIONS = [
        // The scores
        'current_score' => [
            'label' => 'Current score',
            'means' => 'How healthy the movement is now, as a share of the points it could earn.',
            'how' => 'Points earned ÷ points available across the 8 scored areas (84 points), as a percentage, from its newest score: the latest follow-up score, else the baseline, else the approved OHA form before the assessment is closed.',
            'mind' => '“Not Applicable” earns 0 and the maximum stays the same, exactly as in the Excel form.',
        ],
        'baseline' => [
            'label' => 'Baseline',
            'means' => 'The score the cycle starts from.',
            'how' => 'The current score frozen when the assessment is closed. Each closed follow-up then adds a follow-up score; the baseline never changes.',
        ],
        'change_since_baseline' => [
            'label' => 'Points since baseline',
            'means' => 'How far the movement has moved since its cycle began.',
            'how' => 'Current score − baseline, in percentage points.',
            'mind' => 'Shown only once a follow-up has been closed; before that there is nothing to compare.',
        ],
        'health_band' => [
            'label' => 'Health band',
            'means' => 'The score put into words.',
            'how' => 'Excellent from 90%, Strong from 70%, Developing from 50%, At Risk from 20%, Critical below 20%.',
        ],
        'area_score' => [
            'label' => 'Area score',
            'means' => 'How healthy one of the 8 scored areas is.',
            'how' => 'Points earned in the area ÷ the area’s maximum, as a percentage. The maxima: Financial 20, Constitution 20, Governance 12, Strategy 12, M&E 6, Comms 5, Staff 5, Diversity 4. Property Management is asked but not scored.',
        ],
        'points_open' => [
            'label' => 'Points still open',
            'means' => 'Where the score can still grow.',
            'how' => 'Each area’s maximum − the points it earned, from the current score.',
        ],
        'strongest_weakest' => [
            'label' => 'Strongest and weakest',
            'means' => 'The areas with the highest and the lowest area score.',
            'how' => 'Area scores compared as percentages, so a small area is judged fairly beside a large one. When several areas tie, every one of them is named.',
        ],
        'pace_needed' => [
            'label' => 'Pace needed',
            'means' => 'Whether the movement is on course to reach the next band by the end of its cycle.',
            'how' => 'Points still needed to reach the next band up ÷ the follow-ups left in the cycle, beside the points gained per follow-up so far. The red dashed line on the chart.',
        ],

        // The Alliance
        'movements_assessed' => [
            'label' => 'Movements assessed',
            'means' => 'How many movements have a score.',
            'how' => 'Movements whose latest assessment has an approved OHA form, out of all the movements (in the zone, when one is chosen).',
        ],
        'average_score' => [
            'label' => 'Average current score',
            'means' => 'The health of the region (or zone) as a whole.',
            'how' => 'The mean of the assessed movements’ current scores. Beneath it, the mean of their points since baseline, among those followed up.',
            'mind' => 'With few movements, one movement can move the average: read it beside the spread of the movements’ own scores.',
        ],
        'improving' => [
            'label' => 'Improving',
            'means' => 'How many movements are better than when their cycle began.',
            'how' => 'Movements with at least one follow-up score whose current score is above their baseline, out of all those followed up. “Moved up a band”: their band now is above their band at the baseline.',
        ],
        'at_risk' => [
            'label' => 'At Risk or Critical',
            'means' => 'How many movements are in the two lowest bands.',
            'how' => 'Movements whose current score is below 50%. Beneath it, how many were there at their baseline.',
        ],
        'evidence_backed' => [
            'label' => 'Backed by evidence',
            'means' => 'How far the scores can be trusted to documents.',
            'how' => 'Of the “Yes” answers a document can prove, in movements with a baseline, the share with evidence counted in their newest score.',
            'mind' => 'A “Yes” without evidence still earns its point; it is only flagged.',
        ],
        'support_quadrants' => [
            'label' => 'Who needs support',
            'means' => 'Each movement placed by where it is (its current score) and where it is going (its points since baseline).',
            'how' => 'Split at 70% (where Strong begins) and at no change. Strong and improving; Strong, not improving; Improving, not yet strong; Needs support: below 70% with no gain.',
            'mind' => 'A movement with no follow-up yet has no change to show: it sits in the lane beneath, placed by its score.',
        ],
        'growth' => [
            'label' => 'Growth over the cycle',
            'means' => 'Each followed-up movement’s path from its baseline to its newest follow-up score.',
            'how' => 'The current score at the baseline and at each closed follow-up (up to 3).',
        ],
        'red_flags' => [
            'label' => 'Red flags',
            'means' => 'Early warnings that a movement may need support.',
            'how' => 'One flag each for: no gain at Follow-up 1; international funding above '.FormDefinition::INTERNATIONAL_LIMIT.'%; no cash reserve, or one under '.RedFlags::RESERVE_MONTHS.' months; an operating deficit; '.RedFlags::UNPROVEN_SHARE.'% or more of its provable “Yes” answers without evidence. Movements with the most flags come first.',
            'mind' => 'A flag is a prompt to look closer, not a verdict. A dash means it cannot be told yet: no follow-up, or not on the form.',
        ],
        'most_missed' => [
            'label' => 'Most often missed',
            'means' => 'The scored points most movements still lose: a guide for support across the region.',
            'how' => 'For each scored question, how many assessed movements do not earn its point, from their answers as they stand. The 8 missed most.',
        ],
        'plan_delivered' => [
            'label' => 'Plan delivered',
            'means' => 'How much of its ODP a movement carried out by the end of its cycle.',
            'how' => 'ODP tasks done ÷ tasks in the plan, as recorded at the cycle’s last score. Shown for finished cycles only, beside the points gained.',
        ],
        'adoption' => [
            'label' => 'Adoption',
            'means' => 'How common a policy or practice is across the region.',
            'how' => 'The share of assessed movements that answered that it is in place.',
        ],

        // Money and people (from the form as it stands)
        'international_funding' => [
            'label' => 'International funding',
            'means' => 'How much of the income comes from outside the country.',
            'how' => 'The share of income from international sources, as the movement entered it (Q224).',
            'mind' => 'Above '.FormDefinition::INTERNATIONAL_LIMIT.'% the form withholds its point: a sign of dependence on outside funding.',
        ],
        'restricted_funding' => [
            'label' => 'Restricted funding',
            'means' => 'How much of the income is tied to a purpose.',
            'how' => 'The share of income restricted by its donors, as the movement entered it (Q225).',
        ],
        'operating_margin' => [
            'label' => 'Operating margin',
            'means' => 'Whether the movement lives within its means.',
            'how' => '(Income − expenditure) ÷ income, for the last financial year. Below 0: a deficit.',
        ],
        'cash_reserve' => [
            'label' => 'Cash reserve',
            'means' => 'How long the movement could run on its savings.',
            'how' => 'The months of expenses its cash reserve covers (Q241), when it has one (Q239).',
        ],
        'region_median' => [
            'label' => 'Regional median',
            'means' => 'The middle value among the assessed movements.',
            'how' => 'Half the movements are above it and half below. Used for the region’s health shape so that one very large or very small movement does not pull it. Shown on AAYMCA’s dashboard only, never on a movement’s page.',
            'mind' => 'Money is never added up across movements: each reports in its own currency, so only shares and ratios are compared.',
        ],
        'policies_in_place' => [
            'label' => 'Policies in place',
            'means' => 'How many of the key policies the movement has and applies.',
            'how' => 'The policies answered as in place, of the 19 the form asks about (Q412–Q430).',
        ],
        'board_make_up' => [
            'label' => 'Women and under 30 on the Board',
            'means' => 'How diverse the Board is.',
            'how' => 'Women, and members under 30, as a share of the current Board members (Q315–Q319).',
        ],
    ];

    /**
     * One figure's definition.
     *
     * @return array{key: string, label: string, means: string, how: string, mind: ?string}
     */
    public static function get(string $key): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("No KPI definition for “{$key}”.");

        return [
            'key' => $key,
            'label' => $definition['label'],
            'means' => $definition['means'],
            'how' => $definition['how'],
            'mind' => $definition['mind'] ?? null,
        ];
    }

    /**
     * Several definitions, in the order asked.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, means: string, how: string, mind: ?string}>
     */
    public static function many(array $keys): array
    {
        return array_map(self::get(...), $keys);
    }

    /**
     * Every key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
