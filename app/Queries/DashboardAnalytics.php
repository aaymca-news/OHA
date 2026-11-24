<?php

namespace App\Queries;

use App\Enums\BandCode;
use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\Category;
use App\Models\HealthBand;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\Zone;
use App\Oha\Answer;
use App\Oha\CategoryRows;
use App\Oha\EvidenceQuestions;
use App\Oha\FormDefinition;
use App\Oha\Profile;
use App\Oha\ScoreGaps;
use App\Support\Bands;
use App\Support\Pace;
use App\Support\RedFlags;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The figures behind the two dashboards (decided 15 Nov 2026: analytics for decisions,
 * nothing about work or tasks). AAYMCA's: how healthy the region is, who is improving and
 * who needs support, the areas and points most often short, money, policies and people,
 * for one zone or all. A movement's: its own score over the cycle and where it can still
 * grow, beside the region's averages (never another movement's figures).
 *
 * Scores are read as recorded: the current score (the newest baseline's, as every screen
 * shows it), the baseline (the closed assessment's) and the follow-up scores between.
 * Money is never added up across movements, each reporting in its own currency: only
 * shares and ratios are compared.
 */
final class DashboardAnalytics
{
    /** Who needs support: the groups a movement falls in (matrix points' `group`), in the key's order. */
    public const SUPPORT_GROUPS = [
        0 => 'Needs support',
        1 => 'Improving, not yet strong',
        2 => 'Strong, not improving',
        3 => 'Strong and improving',
        4 => 'No follow-up yet',
    ];

    public function __construct(private readonly AllianceInsights $insights) {}

    /**
     * AAYMCA's dashboard: the region, or one zone of it.
     *
     * @return array<string, mixed>
     */
    public function region(?Zone $zone): array
    {
        $insights = $this->insights->inZone($zone?->id);
        $rated = $insights->rated();
        $heat = $insights->heatmap();
        $journeys = $this->journeys($rated->keys()->all());
        $order = $this->bandOrder();

        $movements = $rated->map(function (array $r) use ($journeys, $heat) {
            $journey = $journeys[$r['movement']->id] ?? null;
            $followed = $journey !== null && count($journey['steps']) > 1;
            $cells = collect($heat['rows'])->firstWhere('movement.id', $r['movement']->id)['cells'] ?? [];
            $scored = array_filter($cells, fn (?float $v) => $v !== null);
            $weakestCode = $scored === [] ? null : array_search(min($scored), $scored, true);

            return [
                'movement' => $r['movement'],
                'pct' => (float) $r['status']->pct,
                'band' => $r['status']->band,
                'journey' => $journey,
                'followed' => $followed,
                'change' => $followed ? $journey['change'] : null,
                'areas' => $cells,
                // Its weakest area now, for the pop-up on "Who needs support".
                'weakest' => $weakestCode === null || $weakestCode === false ? null : ['code' => (string) $weakestCode, 'pct' => (float) $scored[$weakestCode]],
                'profile' => $r['profile'],
                'answers' => $r['answers'],
            ];
        })->values();
        $followed = $movements->where('followed', true)->values();
        $withBaseline = $movements->filter(fn (array $m) => $m['journey'] !== null)->values();

        return [
            'figures' => $this->figures($movements->all(), $followed->all(), $withBaseline->all(), $zone, $order),
            'matrix' => $this->matrix($movements->all(), $heat['categories']),
            'bands' => $this->bandsThenNow($withBaseline->all()),
            'flows' => $this->flows($withBaseline->all()),
            'shape' => $this->shape($movements->all(), $heat['categories']),
            'flags' => $this->flags($movements->all()),
            'gains' => $followed->sortByDesc('change')->values()->all(),
            'categories' => $heat['categories'],
            'heatmap' => $movements->sortByDesc('pct')->map(fn (array $m) => [
                'movement' => $m['movement'],
                'pct' => $m['pct'],
                'change' => $m['change'],
                'cells' => $m['areas'],
                'gains' => $m['followed'] ? collect($m['areas'])->map(fn (?float $now, string $code) => $now !== null && isset($m['journey']['first_areas'][$code])
                    ? round($now - $m['journey']['first_areas'][$code], 1) : null)->all() : [],
            ])->values()->all(),
            'missed' => $this->mostMissed($movements->all()),
            'money' => $movements->map(fn (array $m) => [
                'movement' => $m['movement'],
                'international' => $m['profile']['international_pct'] ?? null,
                'restricted' => $m['profile']['restricted_pct'] ?? null,
                'margin' => $m['profile']['finance']['margin_pct'] ?? null,
                'reserve_months' => $m['profile']['finance']['reserve_months'] ?? null,
            ])->sortByDesc(fn (array $r) => $r['international'] ?? -1)->values()->all(),
            'limit' => FormDefinition::INTERNATIONAL_LIMIT,
            'zones' => $this->zones(),
            'adoption' => collect($insights->adoption('policies', Profile::POLICIES))->map(fn ($r) => $r + ['kind' => 'Policy'])
                ->concat(collect($insights->adoption('governance', Profile::GOVERNANCE_PRACTICES))->map(fn ($r) => $r + ['kind' => 'Governance']))
                ->filter(fn (array $r) => $r['answered'] > 0)->sortBy('pct')->take(8)->values()->all(),
            'people' => $insights->people(),
            'cycles' => $withBaseline->filter(fn (array $m) => $m['journey']['finished'])->map(fn (array $m) => [
                'movement' => $m['movement'],
                'plan' => $m['journey']['tasks_total'] ? (int) round($m['journey']['tasks_done'] * 100 / $m['journey']['tasks_total']) : null,
                'change' => $m['journey']['change'],
            ])->sortByDesc('change')->values()->all(),
            // The full detail, folded away on the page.
            'detail' => [
                'mixes' => ['income_mix' => $insights->averageMix('income_mix'), 'expense_mix' => $insights->averageMix('expense_mix')],
                'profiles' => $movements->map(fn (array $m) => ['movement' => $m['movement'], 'profile' => $m['profile']])->all(),
                'policies' => $insights->adoption('policies', Profile::POLICIES),
                'governance' => $insights->adoption('governance', Profile::GOVERNANCE_PRACTICES),
                'vision' => $insights->adoption('vision_2030', Profile::VISION_2030),
            ],
        ];
    }

    /**
     * A movement's own dashboard: its score over the cycle and where it can grow, beside
     * the region's averages. Null figures before its first form is approved.
     *
     * @return array<string, mixed>
     */
    public function movement(MovementStatus $status): array
    {
        $rated = $this->insights->rated();
        $mine = $rated[$status->movement_id] ?? null;
        $assessment = $status->latest_assessment_id !== null ? Assessment::query()->find($status->latest_assessment_id) : null;
        if ($mine === null || $assessment === null) {
            return ['rated' => false];
        }

        $journey = $this->journeys([$status->movement_id])[$status->movement_id] ?? null;
        $steps = $journey !== null ? app(Journeys::class)->of($journey['assessment']) : [];
        $heat = $this->insights->heatmap();
        $regionAreas = $heat['categories']->mapWithKeys(fn (Category $c) => [$c->code => ($v = collect($heat['rows'])->pluck('cells.'.$c->code)->filter(fn ($x) => $x !== null))->isEmpty() ? null : round($v->avg(), 1)]);
        $shortNames = $heat['categories']->pluck('short_name', 'code');
        $icons = $heat['categories']->pluck('icon', 'code');

        $areas = collect(CategoryRows::for($assessment))->filter(fn (array $r) => $r['max'] !== null)->map(fn (array $r) => $r + [
            'short' => $shortNames[$r['code']] ?? $r['name'],
            'baseline' => $journey['first_areas'][$r['code']] ?? null,
            'region' => $regionAreas[$r['code']] ?? null,
        ])->values();
        $top = $areas->max('pct');
        $weakest = $areas->sortBy('pct')->first();

        $profile = $mine['profile'];
        $all = $rated->pluck('profile');
        $boards = $all->filter(fn ($p) => ($p['board']['size'] ?? 0) > 0);
        $boardTotal = $boards->sum(fn ($p) => $p['board']['size']);
        $share = fn (mixed $part, mixed $whole) => $part !== null && $whole ? (int) round((float) $part * 100 / (float) $whole) : null;

        return [
            'rated' => true,
            'pct' => (float) $status->pct,
            'band' => $status->band,
            'journey' => $journey,
            'followed' => $journey !== null && count($journey['steps']) > 1,
            'curve' => Journeys::curve($steps),
            // To the next band up by the end of the cycle (decided 23 Nov 2026).
            'pace' => $journey !== null ? Pace::of($journey['steps'], Journeys::slots()) : null,
            // The health shape: every scored area in form order, with its icon.
            'shape' => $areas->map(fn (array $a) => $a + ['icon' => (string) ($icons[$a['code']] ?? 'circle')])->values()->all(),
            'region' => [
                'mean' => round($rated->avg(fn ($r) => (float) $r['status']->pct), 1),
                'movements' => $rated->count(),
            ],
            'areas' => $areas->sortBy('pct')->values()->all(),
            'strongest' => $areas->where('pct', $top)->pluck('short')->all(),
            'strongest_pct' => $top,
            'weakest' => $weakest,
            'points' => ['earned' => $areas->sum('points'), 'max' => $areas->sum('max')],
            'open' => $areas->filter(fn (array $a) => $a['lost'] > 0)->sortByDesc('lost')->values()->all(),
            'money' => [
                'international' => $profile['international_pct'] ?? null,
                'restricted' => $profile['restricted_pct'] ?? null,
                'margin' => $profile['finance']['margin_pct'] ?? null,
                'reserve_months' => $profile['finance']['reserve_months'] ?? null,
                'limit' => FormDefinition::INTERNATIONAL_LIMIT,
                // Where the money comes from: ours, and the region's average mix.
                'mix' => $profile['income_mix'] ?? null,
                'region_mix' => $this->insights->averageMix('income_mix'),
                'region' => [
                    'international' => $this->median($all->pluck('international_pct')),
                    'restricted' => $this->median($all->pluck('restricted_pct')),
                    'margin' => $this->median($all->pluck('finance.margin_pct')),
                    'with_reserve' => $all->filter(fn ($p) => ($p['finance']['reserve_months'] ?? null) !== null)->count(),
                ],
            ],
            'people' => [
                'staff' => $profile['people']['staff'] ?? null,
                'volunteers' => $profile['people']['volunteers'] ?? null,
                'board' => $profile['board']['size'] ?? null,
                'women' => $share($profile['board']['women'] ?? null, $profile['board']['size'] ?? null),
                'under_30' => $share($profile['board']['under_30'] ?? null, $profile['board']['size'] ?? null),
                'policies' => count(array_filter($profile['policies'] ?? [])),
                'policies_of' => count(Profile::POLICIES),
                'region' => [
                    'staff' => $this->median($all->pluck('people.staff')),
                    'volunteers' => $this->median($all->pluck('people.volunteers')),
                    'board' => $this->median($all->pluck('board.size')),
                    'women' => $share((int) $boards->sum(fn ($p) => $p['board']['women'] ?? 0), (int) $boardTotal),
                    'under_30' => $share((int) $boards->sum(fn ($p) => $p['board']['under_30'] ?? 0), (int) $boardTotal),
                    'policies' => round($all->avg(fn ($p) => count(array_filter($p['policies'] ?? []))), 1),
                ],
            ],
            // The 19 policies, in the form's order: ours (true, false, or null when not answered) beside the region's adoption.
            'policies' => (function () use ($profile) {
                $region = collect($this->insights->adoption('policies', Profile::POLICIES))->keyBy('code');

                return collect(Profile::POLICIES)->map(fn (string $label, string $code) => [
                    'code' => $code,
                    'label' => $label,
                    'ours' => $profile['policies'][$code] ?? null,
                    'region' => $region[$code]['answered'] > 0 ? $region[$code]['pct'] : null,
                ])->values()->all();
            })(),
        ];
    }

    /**
     * Each movement's latest closed assessment and its baselines in force, keyed by movement
     * id: the score at each, the change since the baseline, and the area scores at the baseline.
     *
     * @param  list<int>  $movementIds
     * @return array<int, array{assessment: Assessment, steps: list<float>, first: float, now: float, change: float, first_band: HealthBand|null, band: HealthBand|null, first_areas: array<string, float>, finished: bool, tasks_done: int|null, tasks_total: int|null, evidence_gaps: int}>
     */
    private function journeys(array $movementIds): array
    {
        $assessments = Assessment::query()->latestPerMovement(fn ($q) => $q->whereNotNull('closed_at'))
            ->whereIn('movement_id', $movementIds)
            ->with(['baselines' => fn ($q) => $q->whereNull('superseded_at')->with('total.band')])
            ->get()->filter(fn (Assessment $a) => $a->baselines->isNotEmpty());

        // Each area at the baseline, from the analytics layer (v_analytics_area_scores).
        $firstAreas = DB::table('v_analytics_area_scores')
            ->whereIn('baseline_id', $assessments->map(fn (Assessment $a) => $a->baselines->first()->id))
            ->get(['baseline_id', 'category_code', 'pct'])->groupBy('baseline_id');

        $journeys = [];
        foreach ($assessments as $assessment) {
            $first = $assessment->baselines->first();
            $last = $assessment->baselines->last();
            $journeys[$assessment->movement_id] = [
                'assessment' => $assessment,
                'steps' => $assessment->baselines->map(fn (Baseline $b) => (float) $b->total?->pct)->values()->all(),
                'first' => (float) $first->total?->pct,
                'now' => (float) $last->total?->pct,
                'change' => round((float) $last->total?->pct - (float) $first->total?->pct, 1),
                'first_band' => $first->total?->band,
                'band' => $last->total?->band,
                'first_areas' => collect($firstAreas[$first->id] ?? [])->mapWithKeys(fn ($s) => [(string) $s->category_code => (float) $s->pct])->all(),
                'finished' => $assessment->follow_up_closed_at !== null,
                'tasks_done' => $last->tasks_done,
                'tasks_total' => $last->tasks_total,
                'evidence_gaps' => count($last->evidence_gaps ?? []),
            ];
        }

        return $journeys;
    }

    /**
     * The key figures: how many are assessed, the average score and its change since the
     * baseline, how many improved, how many need support, and how far the "Yes" answers
     * that evidence can prove are proven.
     *
     * @param  list<array<string, mixed>>  $movements
     * @param  list<array<string, mixed>>  $followed
     * @param  list<array<string, mixed>>  $withBaseline
     * @param  Collection<string, float>  $order
     * @return array<string, mixed>
     */
    private function figures(array $movements, array $followed, array $withBaseline, ?Zone $zone, Collection $order): array
    {
        [$movements, $followed, $withBaseline] = [collect($movements), collect($followed), collect($withBaseline)];
        $yes = 0;
        $unproven = 0;
        foreach ($withBaseline as $m) {
            $yes += count(array_filter(array_keys(EvidenceQuestions::LABELS), fn (string $code) => Answer::isYes($m['answers'][$code] ?? null)));
            $unproven += $m['journey']['evidence_gaps'];
        }

        return [
            'total' => Movement::query()->when($zone, fn ($q) => $q->where('zone_id', $zone->id))->count(),
            'assessed' => $movements->count(),
            'mean' => $movements->isEmpty() ? null : round($movements->avg('pct'), 1),
            'followed' => $followed->count(),
            'improved' => $followed->where('change', '>', 0)->count(),
            'mean_change' => $followed->isEmpty() ? null : round($followed->avg('change'), 1),
            'band_up' => $followed->filter(fn (array $m) => ($order[$m['journey']['band']->code ?? ''] ?? 0) > ($order[$m['journey']['first_band']->code ?? ''] ?? 0))->count(),
            'at_risk' => $movements->filter(fn (array $m) => in_array($m['band']?->code, BandCode::NEEDING_SUPPORT, true))->count(),
            'at_risk_baseline' => $withBaseline->filter(fn (array $m) => in_array($m['journey']['first_band']?->code, BandCode::NEEDING_SUPPORT, true))->count(),
            'evidence' => $yes > 0 ? (int) round(($yes - $unproven) * 100 / $yes) : null,
            'evidence_yes' => $yes,
        ];
    }

    /**
     * Who needs support: each movement by its current score (across) and its change since
     * the baseline (up), split at the start of the Strong band and at no change.
     *
     * Rebuilt 23 Nov 2026: each point carries what its pop-up shows (zone, band, its scores
     * over the cycle, its weakest area), and its group, so the key is grouped as the chart is.
     * A movement not followed up yet has no change: it goes in the lane beneath, not on the
     * no-change line.
     *
     * @param  list<array<string, mixed>>  $movements
     * @param  Collection<int, Category>  $categories
     * @return array{points: list<array<string, mixed>>, x: array{from: float, split: float, split_at: float, ticks: list<int>}, y: array{from: float, to: float, zero_at: float, ticks: list<int>}}
     */
    private function matrix(array $movements, Collection $categories): array
    {
        $movements = collect($movements);
        $split = (float) Bands::from('strong');
        $changes = $movements->pluck('change')->filter(fn ($v) => $v !== null);
        $xFrom = (float) min($split - 20, floor(($movements->min('pct') ?? $split) / 10) * 10);
        $yTo = (float) max(10, ceil(($changes->max() ?? 0) / 5) * 5 + 5);
        $yFrom = (float) min(-5, floor(($changes->min() ?? 0) / 5) * 5 - 5);
        $shortNames = $categories->pluck('short_name', 'code');
        $step = $yTo - $yFrom > 30 ? 10 : 5;

        $points = $movements->sortByDesc('pct')->values()->map(function (array $m, int $i) use ($xFrom, $yTo, $yFrom, $split, $shortNames) {
            $weakest = $m['weakest'];

            return [
                'movement' => $m['movement'],
                'n' => $i + 1,
                'pct' => $m['pct'],
                'change' => $m['change'],
                'band' => $m['band'],
                'steps' => $m['journey']['steps'] ?? [$m['pct']],
                'baseline' => $m['journey']['first'] ?? null,
                'weakest' => $weakest === null ? null : ['name' => (string) ($shortNames[$weakest['code']] ?? $weakest['code']), 'pct' => $weakest['pct']],
                // 0 needs support, 1 improving (not yet strong), 2 strong (not improving), 3 strong and improving, 4 no follow-up yet.
                'group' => $m['change'] === null ? 4 : ($m['pct'] >= $split ? ($m['change'] > 0 ? 3 : 2) : ($m['change'] > 0 ? 1 : 0)),
                'left' => round((max($xFrom, $m['pct']) - $xFrom) / (100 - $xFrom) * 100, 2),
                'top' => $m['change'] === null ? null : round(($yTo - $m['change']) / ($yTo - $yFrom) * 100, 2),
            ];
        });

        // Dots that would sit on one another move up (or, in the lane, along) until clear.
        $placed = [];
        $points = $points->map(function (array $p) use (&$placed) {
            for ($tries = 0; $tries < 6; $tries++) {
                $clash = array_any($placed, fn (array $q): bool => $q['lane'] === ($p['top'] === null)
                    && abs($q['left'] - $p['left']) < 2.6 && ($p['top'] === null || abs($q['top'] - $p['top']) < 5.5));
                if (! $clash) {
                    break;
                }
                $p['top'] === null ? $p['left'] = min(98, $p['left'] + 2.8) : $p['top'] = max(1, $p['top'] - 6);
            }
            $placed[] = ['left' => $p['left'], 'top' => $p['top'], 'lane' => $p['top'] === null];

            return $p;
        });

        $yTicks = [];
        for ($t = (int) (ceil($yFrom / $step) * $step); $t <= $yTo; $t += $step) {
            $yTicks[] = $t;
        }

        return [
            'points' => $points->all(),
            'x' => ['from' => $xFrom, 'split' => $split, 'split_at' => round(($split - $xFrom) / (100 - $xFrom) * 100, 2), 'ticks' => range((int) $xFrom, 100, 10)],
            'y' => ['from' => $yFrom, 'to' => $yTo, 'zero_at' => round($yTo / ($yTo - $yFrom) * 100, 2), 'ticks' => $yTicks],
        ];
    }

    /**
     * How the movements with a baseline moved between bands, from the baseline to now: one
     * entry per pair of bands with movements in it (best bands first).
     *
     * @param  list<array<string, mixed>>  $withBaseline
     * @return list<array{from: string, to: string, count: int}>
     */
    private function flows(array $withBaseline): array
    {
        return collect($withBaseline)
            ->groupBy(fn (array $m): string => ($m['journey']['first_band']->code ?? 'notstarted').'>'.($m['band']->code ?? 'notstarted'))
            ->map(fn (Collection $group, string $key) => ['from' => Str::before($key, '>'), 'to' => Str::after($key, '>'), 'count' => $group->count()])
            ->values()->all();
    }

    /**
     * The region's health shape: each scored area's median at the baseline and now, now by
     * zone, and each movement's own, for comparing (decided 23 Nov 2026). Medians, so one
     * movement does not pull the shape.
     *
     * @param  list<array<string, mixed>>  $movements
     * @param  Collection<int, Category>  $categories
     * @return array{areas: list<array{code: string, short: string, name: string, icon: string}>, baseline: list<float|null>, now: list<float|null>, zones: list<array{name: string, values: list<float|null>}>, movements: list<array{id: int, name: string, values: list<float|null>}>}
     */
    private function shape(array $movements, Collection $categories): array
    {
        $movements = collect($movements);
        $codes = $categories->pluck('code')->all();
        $medians = fn (Collection $rows, string $key) => array_map(fn (string $code) => $this->median($rows->pluck($key.'.'.$code)), $codes);
        $withBaseline = $movements->filter(fn (array $m) => $m['journey'] !== null);

        return [
            'areas' => $categories->map(fn (Category $c) => ['code' => (string) $c->code, 'short' => (string) $c->short_name, 'name' => (string) $c->name, 'icon' => (string) $c->icon])->values()->all(),
            'baseline' => $medians($withBaseline->map(fn (array $m) => ['first' => $m['journey']['first_areas']]), 'first'),
            'now' => $medians($movements, 'areas'),
            'zones' => $movements->groupBy(fn (array $m): string => (string) $m['movement']->zone->name)->sortKeys()
                ->map(fn (Collection $rows, string $zone) => ['name' => $zone, 'values' => $medians($rows, 'areas')])->values()->all(),
            'movements' => $movements->sortByDesc('pct')->map(fn (array $m) => [
                'id' => (int) $m['movement']->id,
                'name' => str_replace(' YMCA', '', (string) $m['movement']->name),
                'values' => array_map(fn (string $code) => $m['areas'][$code] ?? null, $codes),
            ])->values()->all(),
        ];
    }

    /**
     * Each movement's red flags (App\Support\RedFlags), most flags first.
     *
     * @param  list<array<string, mixed>>  $movements
     * @return list<array{movement: Movement, checks: array<string, bool|null>, count: int}>
     */
    private function flags(array $movements): array
    {
        return collect($movements)->map(function (array $m) {
            $provable = count(array_filter(array_keys(EvidenceQuestions::LABELS), fn (string $code) => Answer::isYes($m['answers'][$code] ?? null)));
            $checks = RedFlags::of($m['journey']['steps'] ?? [], $m['profile'] ?? [], $provable, $m['journey']['evidence_gaps'] ?? null);

            return ['movement' => $m['movement'], 'checks' => $checks, 'count' => count(array_filter($checks, fn (?bool $c) => $c === true))];
        })->sortBy([['count', 'desc'], fn (array $a, array $b) => strcmp($a['movement']->name, $b['movement']->name)])->values()->all();
    }

    /**
     * How many movements sit in each band at the baseline and now (the same movements: those
     * with a baseline), best band first.
     *
     * @param  list<array<string, mixed>>  $withBaseline
     * @return list<array{band: HealthBand, baseline: int, now: int}>
     */
    private function bandsThenNow(array $withBaseline): array
    {
        $withBaseline = collect($withBaseline);

        return HealthBand::query()->where('min_pct', '>', 0)->orderByDesc('min_pct')->get()->map(fn (HealthBand $band) => [
            'band' => $band,
            'baseline' => $withBaseline->filter(fn (array $m) => $m['journey']['first_band']?->code === $band->code)->count(),
            'now' => $withBaseline->filter(fn (array $m) => $m['band']?->code === $band->code)->count(),
        ])->all();
    }

    /**
     * The scored points most movements still lose, as their answers stand: a guide for
     * support across the region.
     *
     * @param  list<array<string, mixed>>  $movements
     * @return list<array{code: string, name: string, category: string, max: int, missed: int, of: int}>
     */
    private function mostMissed(array $movements): array
    {
        $categories = Category::query()->pluck('short_name', 'code');
        $order = [];
        $missed = [];
        $position = 0;
        foreach (ScoreGaps::scored() as $category => $items) {
            foreach ($items as $item) {
                $order[$item['code']] = $position++;
                $missed[$item['code']] = ['code' => $item['code'], 'name' => ScoreGaps::name($item['code']), 'category' => (string) ($categories[$category] ?? $category), 'max' => $item['max'], 'missed' => 0, 'of' => 0];
            }
        }
        foreach ($movements as $m) {
            if ($m['answers'] === []) {
                continue;
            }
            foreach (array_keys($missed) as $code) {
                $missed[$code]['of']++;
            }
            foreach (ScoreGaps::for($m['answers']) as $items) {
                foreach ($items as $item) {
                    $missed[$item['code']]['missed']++;
                }
            }
        }

        return collect($missed)->filter(fn (array $r) => $r['missed'] > 0)
            ->sortBy(fn (array $r) => [-$r['missed'], -$r['max'], $order[$r['code']]])->take(8)->values()->all();
    }

    /**
     * Every zone side by side, whatever the filter: assessed, average score, the average
     * change of those followed up, and how many need support.
     *
     * @return list<array{zone: Zone, movements: int, assessed: int, mean: float|null, change: float|null, at_risk: int}>
     */
    private function zones(): array
    {
        $statuses = MovementStatus::query()->with(['movement', 'band'])->get()->filter(fn (MovementStatus $s) => $s->movement !== null);
        $journeys = $this->journeys($statuses->whereNotNull('latest_assessment_id')->pluck('movement_id')->all());

        return Zone::query()->orderBy('name')->get()->map(function (Zone $zone) use ($statuses, $journeys) {
            $mine = $statuses->filter(fn (MovementStatus $s) => $s->movement->zone_id === $zone->id);
            $rated = $mine->whereNotNull('latest_assessment_id');
            $changes = $rated->map(fn (MovementStatus $s) => $journeys[$s->movement_id] ?? null)
                ->filter(fn ($j) => $j !== null && count($j['steps']) > 1)->pluck('change');

            return [
                'zone' => $zone,
                'movements' => $mine->count(),
                'assessed' => $rated->count(),
                'mean' => $rated->isEmpty() ? null : round($rated->avg(fn (MovementStatus $s) => (float) $s->pct), 1),
                'change' => $changes->isEmpty() ? null : round($changes->avg(), 1),
                'at_risk' => $mine->whereIn('band_code', BandCode::NEEDING_SUPPORT)->count(),
            ];
        })->all();
    }

    /** @return Collection<string, float> each band's code and where it starts */
    private function bandOrder(): Collection
    {
        return HealthBand::query()->pluck('min_pct', 'code')->map(fn ($v) => (float) $v);
    }

    /** @param  Collection<int, mixed>  $values */
    private function median(Collection $values): ?float
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return null;
        }

        return round($n % 2 === 1 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2, 1);
    }
}
