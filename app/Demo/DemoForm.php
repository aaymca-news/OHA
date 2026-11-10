<?php

namespace App\Demo;

use App\Oha\FormDefinition;
use App\Oha\FormReader;
use DateTimeImmutable;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * An INVENTED OHA form for the demo site: the official blank form (config('oha.blank_form'))
 * filled in with made-up, plausible and consistent answers for a National Movement. Never a
 * real movement's form: every figure, name and sentence comes from the seeded generator and
 * the generic wording in DemoFormText.
 *
 * $strength (0–1) is the share of the form's 84 points the movement earns: each scored
 * criterion has a difficulty (a budget is common, a climate policy is rare), and the
 * easiest criteria, shuffled a little by the seed, are met until the score reaches the
 * strength. So 0.15 is Critical, 0.5 Developing, 0.85 Strong. Follow-up answers agree with
 * what they follow (no strategic plan: none of its follow-ups is "Yes"), shares add up to
 * 100, the board and staff counts add up, and the money figures balance.
 *
 * The same seed always gives the same form.
 */
final class DemoForm
{
    /**
     * Each scored criterion: its category, how hard it is to meet (0 common, 1 rare), the
     * points it earns, and the criterion that must be met first, if any. The points add up
     * to the form's 84.
     *
     * @var array<string, array{0: string, 1: float, 2: int, 3: string|null}>
     */
    private const CRITERIA = [
        'Q201' => ['financial', 0.05, 1, null], 'Q202' => ['financial', 0.15, 1, null], 'Q203' => ['financial', 0.2, 1, null],
        'Q204' => ['financial', 0.25, 1, null], 'Q205' => ['financial', 0.3, 1, null], 'Q206' => ['financial', 0.45, 1, 'Q205'],
        'Q207' => ['financial', 0.7, 1, null], 'income1' => ['financial', 0.4, 1, null], 'income2' => ['financial', 0.7, 1, 'income1'],
        'Q223' => ['financial', 0.35, 1, null], 'Q225' => ['financial', 0.5, 1, null], 'Q227' => ['financial', 0.75, 1, null],
        'Q228' => ['financial', 0.65, 1, null], 'Q238' => ['financial', 0.55, 3, null], 'Q239' => ['financial', 0.6, 1, null],
        'Q241' => ['financial', 0.85, 1, 'Q239'], 'Q242' => ['financial', 0.3, 1, null], 'Q244' => ['financial', 0.3, 1, null],

        'Q320' => ['governance', 0.5, 1, null], 'Q322' => ['governance', 0.2, 1, null], 'Q323' => ['governance', 0.3, 1, null],
        'Q325' => ['governance', 0.3, 1, null], 'Q325#2' => ['governance', 0.7, 1, null], 'Q326#2' => ['governance', 0.1, 1, null],
        'Q327' => ['governance', 0.45, 1, null], 'Q328' => ['governance', 0.05, 1, null], 'Q330' => ['governance', 0.6, 1, null],
        'Q331' => ['governance', 0.8, 1, null], 'Q332' => ['governance', 0.55, 1, null], 'Q333' => ['governance', 0.75, 1, null],

        'Q410' => ['constitution', 0.2, 1, null], 'Q412' => ['constitution', 0.35, 1, null], 'Q413' => ['constitution', 0.15, 1, null],
        'Q414' => ['constitution', 0.5, 1, null], 'Q415' => ['constitution', 0.55, 1, null], 'Q416' => ['constitution', 0.5, 1, null],
        'Q417' => ['constitution', 0.1, 1, null], 'Q418' => ['constitution', 0.1, 1, null], 'Q419' => ['constitution', 0.75, 1, null],
        'Q420' => ['constitution', 0.9, 1, null], 'Q421' => ['constitution', 0.7, 1, null], 'Q422' => ['constitution', 0.3, 1, null],
        'Q423' => ['constitution', 0.2, 1, null], 'Q424' => ['constitution', 0.4, 1, null], 'Q425' => ['constitution', 0.4, 1, null],
        'Q426' => ['constitution', 0.3, 1, null], 'Q427' => ['constitution', 0.65, 1, null], 'Q428' => ['constitution', 0.95, 1, null],
        'Q429' => ['constitution', 0.6, 1, null], 'Q430' => ['constitution', 0.65, 1, null],

        'Q511' => ['me', 0.1, 1, null], 'Q512' => ['me', 0.6, 1, null], 'Q513' => ['me', 0.25, 1, null],
        'Q514' => ['me', 0.7, 1, null], 'Q515' => ['me', 0.55, 1, null], 'Q516' => ['me', 0.8, 1, null],

        'Q610' => ['strategy', 0.15, 3, null], 'Q612' => ['strategy', 0.2, 1, 'Q610'], 'Q613' => ['strategy', 0.2, 1, 'Q610'],
        'Q614' => ['strategy', 0.4, 1, 'Q610'], 'Q615' => ['strategy', 0.5, 1, 'Q610'], 'Q616' => ['strategy', 0.6, 1, 'Q610'],
        'Q618' => ['strategy', 0.7, 1, 'Q610'], 'Q619' => ['strategy', 0.6, 1, 'Q610'], 'Q620' => ['strategy', 0.45, 1, 'Q610'],
        'Q630' => ['strategy', 0.05, 1, null],

        'Q711' => ['diversity', 0.35, 1, null], 'Q712' => ['diversity', 0.45, 1, null],
        'Q714' => ['diversity', 0.65, 1, null], 'Q716' => ['diversity', 0.55, 1, null],

        'Q810' => ['comms', 0.5, 1, null], 'Q811' => ['comms', 0.35, 1, null], 'Q812' => ['comms', 0.6, 1, null],
        'Q813' => ['comms', 0.3, 1, null], 'Q814' => ['comms', 0.85, 1, null],

        'Q1001' => ['staff', 0.4, 1, null], 'Q1002' => ['staff', 0.6, 1, null], 'Q1003' => ['staff', 0.5, 1, null],
        'Q1004' => ['staff', 0.2, 1, null], 'Q1007' => ['staff', 0.25, 1, null],
    ];

    /** Sources of income on each side of Q223/Q224 (local vs international). */
    private const LOCAL_INCOME = ['Q214', 'Q215', 'Q216', 'Q217', 'Q218', 'Q222'];

    private const INTERNATIONAL_INCOME = ['Q219', 'Q220', 'Q221'];

    /**
     * Where each answer, area of improvement and sign-off line sits in the blank form,
     * read once from the form itself.
     *
     * @var array{answers: array<string, array{sheet: string, cell: string}>, comments: array<string, array{sheet: string, cell: string}>, signoff: array<string, array{sheet: string, cell: string}>}|null
     */
    private static ?array $cells = null;

    private readonly Randomizer $rng;

    private readonly float $strength;

    /** @var array<string, bool> criterion => met */
    private array $met = [];

    /** @var array<string, string|int> question code => answer */
    private array $answers = [];

    /** @var array<string, string> question code => "Additional Information" */
    private array $notes = [];

    /** @var array<string, string> category code => areas of improvement */
    private array $comments = [];

    /** @var array<string, string> sign-off label => Yes/No */
    private array $signoff = [];

    /** @var array<string, string> sign-off label => who signed, and when (beside the answer) */
    private array $signedBy = [];

    private bool $federated = false;

    private function __construct(
        private readonly string $movementName,
        private readonly string $country,
        private readonly int $year,
        float $strength,
        int $seed,
    ) {
        $this->rng = new Randomizer(new Mt19937($seed));
        $this->strength = max(0.0, min(1.0, $strength));
    }

    /**
     * Fills a copy of the blank OHA form with invented answers and returns the path of the
     * temporary .xlsx. The caller deletes it.
     *
     * @param  int  $year  the year of the assessment: the figures are for the year before
     * @param  float  $strength  0–1, the share of the 84 points earned
     */
    public static function make(string $movementName, string $country, int $year, float $strength, int $seed): string
    {
        $form = new self($movementName, $country, $year, $strength, $seed);
        $form->build();

        return $form->write();
    }

    private function build(): void
    {
        $this->choose();
        $this->general();
        $this->financial();
        $this->governance();
        $this->constitution();
        $this->monitoring();
        $this->strategy();
        $this->diversity();
        $this->communications();
        $this->property();
        $this->staff();
        $this->improvements();
        $this->signoffs();
    }

    /**
     * Decides which criteria are met. Each criterion's place in the queue is its difficulty
     * shifted at random; a follow-up always queues after what it follows, and "no debt"
     * (Q242) after "no debt risk" (Q244), since a YMCA without debt carries no debt risk.
     * Criteria are met from the front of the queue until the points reach the target.
     */
    private function choose(): void
    {
        $total = array_sum(array_column(self::CRITERIA, 2));
        $target = min($total, (int) round($this->strength * $total) + $this->rng->getInt(0, 2));

        $queue = [];
        foreach (self::CRITERIA as $key => [, $difficulty, , $after]) {
            $queue[$key] = $difficulty + $this->rng->getFloat(-0.3, 0.3);
            if ($after !== null) {
                $queue[$key] = max($queue[$key], $queue[$after] + 0.001);
            }
        }
        $queue['Q244'] = min($queue['Q244'], $queue['Q242'] - 0.001);
        asort($queue);

        $points = 0;
        foreach (array_keys($queue) as $key) {
            [, , $worth, $after] = self::CRITERIA[$key];
            $met = $points + $worth <= $target
                && ($after === null || $this->met[$after])
                && ($key !== 'Q242' || $this->met['Q244']);
            $this->met[$key] = $met;
            $points += $met ? $worth : 0;
        }
    }

    /* ---- the sheets ------------------------------------------------------- */

    private function general(): void
    {
        $this->federated = $this->chance(0.4);
        $legal = $this->federated ? 'National Council of YMCAs of '.$this->country : 'Young Men’s Christian Association of '.$this->country;
        $city = DemoFormText::CITIES[$this->country] ?? 'the capital';

        $this->answers += [
            'Q101' => $legal.', known as '.$this->movementName,
            'Q102' => $this->country,
            'Q104' => 'Plot '.$this->int(12, 480).', '.$this->pick(DemoFormText::STREETS).', P.O. Box '.$this->int(100, 9999).', '.$city,
            'Q105' => '1 January – 31 December '.($this->year - 1),
            'Q106' => 'Yes',
            'Q107' => $this->pick(DemoFormText::REGISTRATION),
            'Q108' => $this->chance(0.75) ? 'Yes' : 'No',
            'Q109' => $this->chance(0.6) ? 'Yes' : 'Not Applicable',
            'Q110' => $this->chance(0.3) ? 'Yes' : 'No',
            'Q112' => $this->federated ? 'Federative Structure' : 'Single Entity',
        ];
        if ($this->answers['Q108'] === 'Yes') {
            $this->answers['Q111'] = 'Tax exemption on income from charitable activities, renewed every three years.';
        }

        if ($this->federated) {
            $members = $this->int(3, 14);
            $groups = $this->int(0, 8);
            $this->answers += ['Q113' => $members + $groups, 'Q114' => $members, 'Q115' => $groups, 'Q117' => $this->int(0, min(3, $members - 1))];
        } else {
            $this->answers['Q118'] = $this->int(2, 12);
        }
    }

    private function financial(): void
    {
        foreach (['Q201', 'Q202', 'Q203', 'Q204', 'Q205', 'Q207', 'Q228', 'Q238', 'Q239'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        $this->answers['Q206'] = match (true) {
            $this->met['Q206'] => '1 - 5 years',
            $this->met['Q205'] => $this->pick(['Less than 1 year', 'More than 5 years']),
            default => 'Not Applicable',
        };
        $this->answers['Q227'] = $this->met['Q227'] ? 'No' : 'Yes';

        [$code, $currency, $rate] = DemoFormText::CURRENCIES[$this->country] ?? ['USD', 'US Dollar', 1.0];
        $this->answers['Q208'] = $currency.' ('.$code.')';

        // The year's figures, on the local currency's scale; a stronger YMCA is a little larger.
        $income = $this->money(60_000 * (1 + 0.8 * $this->strength) * exp($this->rng->getFloat(0, 2.3)) * $rate);
        $balanceShare = $this->met['Q238'] ? $this->rng->getFloat(0.02, 0.10) : $this->rng->getFloat(-0.08, 0.02);
        $spent = $this->money($income * (1 - $balanceShare));

        $months = $this->met['Q239'] ? ($this->met['Q241'] ? $this->int(7, 12) : $this->int(1, 6)) : 0;
        $reserve = $months > 0 ? $this->money($spent / 12 * $months * $this->rng->getFloat(0.9, 1.1)) : 0;
        $assets = $this->money($income * $this->rng->getFloat(0.2, 0.55));
        if ($reserve > $assets * 0.85) {
            // The reserve is part of the current assets.
            $assets = $this->money($reserve / $this->rng->getFloat(0.6, 0.8));
        }
        $liabilities = $this->money($assets * $this->rng->getFloat(0.6 - 0.4 * $this->strength, 0.95 - 0.4 * $this->strength));

        $this->answers += [
            'Q209' => $assets, 'Q210' => $liabilities, 'Q211' => $income, 'Q212' => $spent, 'Q213' => $income - $spent,
            'Q246' => $this->money($spent * $this->rng->getFloat(0.01, 0.05)),
        ];
        if ($months > 0) {
            $this->answers += ['Q240' => $reserve, 'Q241' => $months];
        }

        // Debt: none, or some with or without a risk of repaying it.
        $this->answers['Q242'] = $this->met['Q242'] ? 'No' : 'Yes';
        if ($this->met['Q242'] || $this->met['Q244']) {
            $this->answers += ['Q244' => 'No', 'Q245' => 'No'];
        } else {
            $risk = $this->pick([['Yes', 'No'], ['No', 'Yes'], ['Yes', 'Yes']]);
            $this->answers += ['Q244' => $risk[0], 'Q245' => $risk[1]];
        }
        if (! $this->met['Q242']) {
            $this->answers['Q243'] = $this->money($income * $this->rng->getFloat(0.05, 0.4));
        }

        $supports = $this->chance(0.05 + 0.1 * $this->strength);
        $this->answers['Q247'] = $supports ? 'Yes' : 'No';
        if ($supports) {
            $this->answers['Q248'] = $this->money($income * $this->rng->getFloat(0.005, 0.02));
        }

        $this->incomeShares();

        $restricted = $this->met['Q225'] ? $this->int(35, 80) : $this->int(86, 96);
        $this->answers += ['Q225' => $restricted, 'Q226' => 100 - $restricted];

        $expenses = $this->apportion([
            'Q229' => $this->rng->getFloat(35, 60), 'Q230' => $this->rng->getFloat(1, 5), 'Q231' => $this->rng->getFloat(18, 35),
            'Q232' => $this->rng->getFloat(1, 8), 'Q233' => $this->rng->getFloat(0, 5), 'Q234' => $this->rng->getFloat(0.5, 3),
            'Q235' => $this->rng->getFloat(0, 2), 'Q236' => $this->rng->getFloat(5, 12), 'Q237' => $this->rng->getFloat(0, 4),
        ], 100);
        // "Leave a line blank if it is 0", as the form asks.
        $this->answers += array_filter($expenses);
    }

    /**
     * Income by source (Q214–Q222), adding up to 100, with the largest source in the band
     * the met criteria call for (over 65, 50–65, under 50), and the international share
     * (Q224) the international sources add up to: at most 75 when Q223 is met.
     */
    private function incomeShares(): void
    {
        $level = ($this->met['income1'] ? 1 : 0) + ($this->met['income2'] ? 1 : 0);
        [$min, $max] = [[66, 80], [50, 65], [30, 45]][$level];

        if ($this->met['Q223']) {
            // Mostly local money; the largest source is local.
            $top = $this->int($min, $max);
            $international = $this->int(8, min(70, 100 - $top));
            // Paid services or a government subsidy; membership fees only lead a diverse mix.
            $largest = $this->pick($level === 2 ? ['Q214', 'Q215', 'Q216'] : ['Q214', 'Q214', 'Q215']);
            $others = array_values(array_diff(self::LOCAL_INCOME, [$largest]));
            $shares = [$largest => $top]
                + $this->split(100 - $international - $top, $others, $top)
                + $this->split($international, self::INTERNATIONAL_INCOME, $top);
        } else {
            // Mostly international money; the largest source is international.
            $international = $this->int(78, 92);
            $top = $level === 2 ? $this->int(max($min, (int) ceil($international / 3)), $max) : $this->int($min, min($max, $international));
            $largest = $this->pick(self::INTERNATIONAL_INCOME);
            $others = array_values(array_diff(self::INTERNATIONAL_INCOME, [$largest]));
            $shares = [$largest => $top]
                + $this->split($international - $top, $others, $top)
                + $this->split(100 - $international, self::LOCAL_INCOME, $top);
        }

        $this->answers += array_filter($shares);
        $this->answers += ['Q223' => 100 - $international, 'Q224' => $international];
    }

    private function governance(): void
    {
        $board = $this->int(7, 13);
        $women = (int) round($board * ($this->met['Q712'] ? $this->rng->getFloat(0.4, 0.55) : $this->rng->getFloat(0.12, 0.3)));
        $young = $this->met['Q711'] ? $this->int(2, max(2, intdiv($board, 3))) : $this->int(0, 1);
        $term = $this->met['Q322'] ? $this->int(3, 4) : $this->pick([5, 5, 6]);

        $this->answers += [
            'Q310' => $this->chance(0.7) ? 'General Assembly' : 'Board of Directors',
            'Q312' => $this->pick(DemoFormText::ELECTED_BY),
            'Q313' => max(5, $board - $this->int(0, 2)),
            'Q314' => $board + $this->int(0, 4),
            'Q315' => $women,
            'Q316' => $board - $women,
            'Q317' => 0,
            'Q318' => $young,
            'Q319' => $this->int(1, intdiv($board, 2)),
            'Q322' => $term,
            'Q326#2' => $this->met['Q326#2'] ? '2 or more times' : '1 time',
            'Q329' => $this->met['Q330'] || $this->met['Q331'] ? 'Yes' : 'No',
        ];
        foreach (['Q320', 'Q323', 'Q325', 'Q325#2', 'Q327', 'Q328', 'Q330', 'Q331', 'Q332', 'Q333'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        if ($this->met['Q320']) {
            $this->answers['Q321'] = $this->pick(DemoFormText::SKILLS_APPROACH);
        }
        if ($this->met['Q323']) {
            $this->answers['Q324'] = $term * 2;
        }
        if ($this->met['Q325']) {
            $this->answers['Q326'] = $term * 2;
        }
    }

    private function constitution(): void
    {
        foreach (self::CRITERIA as $key => [$category]) {
            if ($category === 'constitution') {
                $this->answers[$key] = $this->yes($key);
            }
        }
        // An outdated constitution is due for change.
        $this->answers['Q411'] = ! $this->met['Q410'] || $this->chance(0.2) ? 'Yes' : 'No';
        if ($this->chance($this->strength - 0.3)) {
            $this->answers['Q431'] = 'A volunteer management policy and a social media policy.';
        }
    }

    private function monitoring(): void
    {
        foreach (['Q511', 'Q512', 'Q513', 'Q514', 'Q515', 'Q516'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        if ($this->met['Q516']) {
            $this->answers['Q517'] = $this->pick(DemoFormText::IMPACT_TOOLS);
        }
        $this->answers['Q518'] = $this->int(15, 400) * 100;
    }

    private function strategy(): void
    {
        foreach (['Q610', 'Q612', 'Q613', 'Q614', 'Q615', 'Q616', 'Q618', 'Q619', 'Q620', 'Q630'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        if ($this->met['Q610']) {
            $adopted = $this->int($this->year - 4, $this->year - 1);
            $month = $this->pick(['January', 'March', 'June', 'September', 'November']);
            $this->answers['Q611'] = 'Adopted in '.$month.' '.$adopted.', for '.$adopted.'–'.($adopted + 4);
            if ($this->chance(0.6)) {
                $this->answers['Q617'] = 'Yes: local government and partner churches were consulted.';
            }
        }
        if ($this->met['Q620']) {
            foreach (['Q621', 'Q622', 'Q623', 'Q624', 'Q625', 'Q626', 'Q627'] as $code) {
                $this->answers[$code] = $this->chance(0.6 + 0.4 * $this->strength) ? 'Yes' : 'No';
            }
        }
        $this->answers['Q628'] = $this->pick(DemoFormText::VISIONS);
        $this->answers['Q629'] = $this->pick(DemoFormText::VALUES);
        $this->answers['Q631'] = $this->chance(0.95) ? 'Yes' : 'No';
        foreach (['Q632', 'Q633', 'Q634', 'Q635'] as $code) {
            $this->answers[$code] = $this->chance(0.4 + 0.5 * $this->strength) ? 'Yes' : 'No';
        }
    }

    private function diversity(): void
    {
        $this->answers['Q710'] = 'Yes';
        foreach (['Q711', 'Q712', 'Q714', 'Q716'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        $this->answers['Q713'] = $this->chance(0.1 + 0.5 * $this->strength) ? 'Yes' : 'No';

        $board = (int) $this->answers['Q315'] + (int) $this->answers['Q316'];
        if ($this->met['Q711']) {
            $this->notes['Q711'] = $this->answers['Q318'].' of the '.$board.' Board members are under 30.';
        }
        if ($this->met['Q712']) {
            $this->notes['Q712'] = $this->answers['Q315'].' women and '.$this->answers['Q316'].' men sit on the Board.';
        }
        if ($this->answers['Q713'] === 'Yes') {
            $this->notes['Q713'] = 'Every region of the country is represented on the Board.';
        }
        if ($this->met['Q714']) {
            $this->answers['Q715'] = $this->pick(DemoFormText::BOARD_INCLUSION);
        }
        if ($this->met['Q716']) {
            $this->answers['Q717'] = $this->pick(DemoFormText::YOUTH_INCLUSION);
        }
    }

    private function communications(): void
    {
        foreach (['Q810', 'Q811', 'Q812', 'Q813', 'Q814'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        if ($this->federated) {
            $this->answers['Q815'] = $this->pick(DemoFormText::LICENSING);
        }
    }

    /** Property is not scored: it follows the strength loosely. */
    private function property(): void
    {
        $owns = $this->chance(0.6 + 0.3 * $this->strength);
        $atRisk = $owns && $this->chance(0.6 - 0.4 * $this->strength);
        $this->answers['Q910'] = $owns ? 'Yes' : 'No';
        $this->answers['Q913'] = $atRisk ? 'Yes' : 'No';

        if ($owns) {
            $this->answers['Q911'] = implode('; ', array_slice($this->rng->shuffleArray(DemoFormText::PROPERTIES), 0, $this->int(2, 3)));
            $this->answers['Q912'] = $this->chance(0.4 + 0.5 * $this->strength) ? 'Yes' : 'No';
            $this->answers['Q915'] = $this->chance(0.2 + 0.6 * $this->strength) ? 'Yes' : 'No';
        }
        if ($atRisk) {
            $this->answers['Q914'] = $this->pick(DemoFormText::PROPERTY_RISKS);
        }
    }

    private function staff(): void
    {
        foreach (['Q1001', 'Q1002', 'Q1003', 'Q1004', 'Q1007'] as $code) {
            $this->answers[$code] = $this->yes($code);
        }
        $core = $this->int(4, 35);
        $temporary = $this->int(0, 8);
        $project = $this->int(0, 15);
        $total = $core + $temporary + $project;

        $this->answers += [
            'Q1005' => $this->pick(DemoFormText::SOCIAL_SECURITY),
            'Q1008' => $total, 'Q1009' => $core, 'Q1010' => $temporary, 'Q1011' => $project,
            'Q1012' => $this->int(0, (int) round($total * 0.6)),
            'Q1013' => $this->int(25, 600),
        ];
        if ($this->chance(0.5)) {
            $this->answers['Q1006'] = 'Some programme work is delivered by consultants on short contracts.';
        }
    }

    /**
     * Each sheet's "areas of improvement": the most basic criteria it does not meet (at most
     * three), or, with all met, a line about keeping it up.
     */
    private function improvements(): void
    {
        foreach (array_keys(FormDefinition::categories()) as $category) {
            $gaps = array_filter(self::CRITERIA, fn (array $c, string $key) => $c[0] === $category && ! $this->met[$key], ARRAY_FILTER_USE_BOTH);
            uasort($gaps, fn (array $a, array $b) => $a[1] <=> $b[1]);
            $lines = array_map(fn (string $key) => DemoFormText::IMPROVE[$key], array_slice(array_keys($gaps), 0, 3));

            if ($category === 'property') {
                $owns = $this->answers['Q910'] === 'Yes';
                $lines = array_values(array_filter([
                    $owns && $this->answers['Q912'] === 'No' ? 'Register and document every property.' : null,
                    $this->answers['Q913'] === 'Yes' ? 'Resolve the risks to the property noted above.' : null,
                    $owns && $this->answers['Q915'] === 'No' ? 'Prepare a property development plan.' : null,
                    $owns ? null : 'Consider whether owning a facility would support programmes in the long term.',
                ]));
            }

            $this->comments[$category] = $lines === [] ? DemoFormText::SUSTAIN[$category] : implode(' ', $lines);
        }
    }

    private function signoffs(): void
    {
        $lines = [
            'Chair / President of the Board' => [true, 'Board Chair'],
            'National General Secretary (NGS)' => [true, 'General Secretary'],
            'Key Staff' => [$this->chance(0.9), 'Finance and Programmes Managers'],
            'National Board' => [$this->chance(0.5 + 0.4 * $this->strength), 'Board members'],
        ];
        $day = new DateTimeImmutable(sprintf('%d-%02d-%02d', $this->year, $this->int(1, 5), $this->int(1, 28)));

        foreach ($lines as $label => [$took, $who]) {
            $this->signoff[$label] = $took ? 'Yes' : 'No';
            if ($took) {
                $name = in_array($label, ['Chair / President of the Board', 'National General Secretary (NGS)'], true) ? $this->person().' ('.$who.')' : $who;
                $this->signedBy[$label] = $name.', '.$day->modify('+'.$this->int(0, 6).' days')->format('j F Y');
            }
        }
    }

    /* ---- the workbook ----------------------------------------------------- */

    private function write(): string
    {
        $cells = self::cells();
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $book = $reader->load((string) config('oha.blank_form'));

        try {
            foreach ($this->answers as $code => $value) {
                $this->put($book, $cells['answers'][$code] ?? throw new LogicException("The blank form has no {$code}."), $value);
            }
            foreach ($this->notes as $code => $note) {
                $this->put($book, $this->beside($cells['answers'][$code], 'F'), $note);
            }
            foreach ($this->comments as $category => $text) {
                $this->put($book, $cells['comments'][$category] ?? throw new LogicException("The blank form has no {$category} comment."), $text);
            }
            foreach ($this->signoff as $label => $value) {
                $where = $cells['signoff'][$label] ?? throw new LogicException("The blank form has no {$label} sign-off.");
                $this->put($book, $where, $value);
                if (isset($this->signedBy[$label])) {
                    $this->put($book, $this->beside($where, 'E'), $this->signedBy[$label]);
                }
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'demo-oha');
            @unlink($path);
            $path .= '.xlsx';
            $this->save($book, $path);
        } finally {
            $book->disconnectWorksheets();
        }

        return $path;
    }

    /**
     * Where each answer, area of improvement and sign-off line is, as the platform finds
     * them (see RecordsFormChecks::cells): read from the blank form, once.
     *
     * @return array{answers: array<string, array{sheet: string, cell: string}>, comments: array<string, array{sheet: string, cell: string}>, signoff: array<string, array{sheet: string, cell: string}>}
     */
    private static function cells(): array
    {
        if (self::$cells !== null) {
            return self::$cells;
        }

        $read = (new FormReader)->read((string) config('oha.blank_form'));
        $cells = ['answers' => [], 'comments' => [], 'signoff' => []];

        foreach ($read->locations as $code => $where) {
            $column = $where['sheet'] === $read->generalSheet ? FormDefinition::GENERAL_COLUMNS['answer'] : FormDefinition::CATEGORY_COLUMNS['answer'];
            $cells['answers'][$code] = ['sheet' => $where['sheet'], 'cell' => $column.$where['row']];
        }
        foreach ($read->comments as $category => $comment) {
            $sheet = $read->categorySheets[$category] ?? null;
            if ($sheet !== null && $comment['row'] !== null) {
                $cells['comments'][$category] = ['sheet' => $sheet, 'cell' => 'D'.$comment['row']];
            }
        }
        foreach ($read->signoff as $label => $line) {
            if ($read->generalSheet !== null) {
                $cells['signoff'][$label] = ['sheet' => $read->generalSheet, 'cell' => 'D'.$line['row']];
            }
        }

        return self::$cells = $cells;
    }

    /**
     * The cell in another column of the same row.
     *
     * @param  array{sheet: string, cell: string}  $where
     * @return array{sheet: string, cell: string}
     */
    private function beside(array $where, string $column): array
    {
        return ['sheet' => $where['sheet'], 'cell' => $column.preg_replace('/^[A-Z]+/', '', $where['cell'])];
    }

    /**
     * As FormWorkbookWriter puts an answer: a percentage-formatted cell takes 25% as 0.25.
     *
     * @param  array{sheet: string, cell: string}  $where
     */
    private function put(Spreadsheet $book, array $where, string|int $value): void
    {
        $sheet = $book->getSheetByName($where['sheet']) ?? throw new LogicException("The blank form has no sheet {$where['sheet']}.");
        $percentCell = str_contains((string) $sheet->getStyle($where['cell'])->getNumberFormat()->getFormatCode(), '%');
        $sheet->getCell($where['cell'])->setValue(is_int($value) && $percentCell ? $value / 100 : $value);
    }

    /** Saved with the form's formulas worked out, so its printed totals agree with the answers. */
    private function save(Spreadsheet $book, string $path): void
    {
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setIncludeCharts(true);
        try {
            $writer->save($path);
        } catch (Throwable) {
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        }
    }

    /* ---- helpers ---------------------------------------------------------- */

    private function yes(string $criterion): string
    {
        return $this->met[$criterion] ? 'Yes' : 'No';
    }

    private function chance(float $probability): bool
    {
        return $this->rng->getFloat(0, 1) < $probability;
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, max($min, $max));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /** An invented person: a first name and a surname drawn apart. */
    private function person(): string
    {
        return $this->pick(DemoFormText::FIRST_NAMES).' '.$this->pick(DemoFormText::SURNAMES);
    }

    /** A money figure rounded to three significant figures, as a finance lead would give it. */
    private function money(float $value): int
    {
        $value = max(1.0, $value);
        $step = 10 ** max(0, (int) floor(log10($value)) - 2);

        return (int) (round($value / $step) * $step);
    }

    /**
     * Whole shares of $total in proportion to the weights (largest remainder), adding up exactly.
     *
     * @param  array<string, float>  $weights
     * @return array<string, int>
     */
    private function apportion(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        $exact = array_map(fn (float $w): float => $sum > 0 ? $w / $sum * $total : (float) $total / count($weights), $weights);
        $shares = array_map(fn (float $v) => (int) floor($v), $exact);

        $remainders = array_map(fn (float $v, int $s) => $v - $s, $exact, $shares);
        $order = array_combine(array_keys($exact), $remainders);
        arsort($order);
        foreach (array_slice(array_keys($order), 0, $total - array_sum($shares)) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }

    /**
     * $total split at random between $keys, none above $cap (the caller makes sure it fits).
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function split(int $total, array $keys, int $cap): array
    {
        $weights = [];
        foreach ($keys as $key) {
            $weights[$key] = $this->chance(0.25) ? 0.0 : $this->rng->getFloat(1, 10);
        }
        $shares = $this->apportion($weights, $total);

        // Anything above the cap goes to the smallest share.
        while (max($shares) > $cap) {
            $over = (string) array_search(max($shares), $shares, true);
            $under = (string) array_search(min($shares), $shares, true);
            $move = min(max($shares) - $cap, $cap - min($shares));
            $shares[$over] -= $move;
            $shares[$under] += $move;
        }

        return $shares;
    }
}
