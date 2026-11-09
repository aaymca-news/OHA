<?php

namespace App\Oha\Report;

use App\Enums\ArtefactState;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\FormUpload;
use App\Models\HealthBand;
use App\Oha\Answer;
use App\Oha\FormDefinition;
use App\Oha\Profile;
use App\Oha\Question;
use App\Oha\Scorer;
use Illuminate\Support\Collection;

/**
 * Drafts the health assessment report from the movement's OHA form, section by section
 * in the order of the official report template (Version 5): the title, the overview,
 * the overall score and score table, the key strengths, risks and opportunities, and the
 * analysis of each of the nine categories with its opportunities for growth.
 *
 * Facts come straight from the form, and the scores from the same place as the report
 * check's (the approved form, or the latest upload), so the draft never disagrees with
 * the form. The wording comes from the SentenceBank. What the form leaves unanswered is
 * left as "[To complete: …]", never guessed. Everything it writes is in English.
 *
 * @phpstan-type Row array{code: string, name: string, points: float, max: int|null, pct: float|null, band: string|null, band_label: string|null, color: string|null}
 */
final class DraftReport
{
    /** The template's own title for each category's analysis in Section C. */
    public const CATEGORY_TITLES = [
        'financial' => 'Financial Stability',
        'governance' => 'Governance',
        'constitution' => 'Constitution, By-Laws, Policies and Procedures',
        'me' => 'Monitoring and Evaluation',
        'strategy' => 'Strategic Planning and Mission',
        'diversity' => 'Diversity and Youth Participation',
        'comms' => 'Communications and Branding',
        'property' => 'Property Management',
        'staff' => 'Staff and Volunteer Development',
    ];

    /** How many points a summary list (strengths, risks, opportunities) takes at most. */
    private const SUMMARY = 5;

    public function __construct(private readonly Scorer $scorer) {}

    /**
     * The draft: which form upload it was made from, and its sections.
     *
     * @return array{form_upload_id: int, sections: list<array<string, mixed>>}
     */
    public function for(Assessment $assessment): array
    {
        $upload = $this->sourceUpload($assessment) ?? throw new WorkflowRuleBroken('The report can be drafted once the OHA form is uploaded and read.');
        $answers = $upload->answers ?? [];
        $movement = $assessment->movement;
        $name = $movement->name;

        $rows = $this->scoreRows($assessment, $upload);
        $items = $this->items($answers);
        $weighted = collect($rows)->filter(fn (array $r) => $r['max'] !== null);
        $total = (float) $weighted->sum('points');
        $available = (int) $weighted->sum('max');
        $overallPct = round($total * 100 / max(1, $available), 2);
        $overallBand = $this->band($overallPct);

        $sections = [
            $this->title($assessment),
            $this->overview($answers, $name),
            [
                'key' => 'overall', 'title' => 'OVERALL ORGANISATIONAL HEALTH STATUS AND KEY PRIORITIES', 'kind' => 'text', 'locked' => false,
                'text' => "Using the Global YMCA Strategic Alignment and Movement Strengthening (SAMs) Organisational Health Assessment Framework, {$name} attained an overall score of "
                    .number_format($overallPct, 2).'% ('.$this->number($total)." of {$available} points). This reflects ".SentenceBank::BAND_MEANING[$overallBand->code].'.',
                'sources' => [],
            ],
            [
                'key' => 'scores', 'title' => 'Scores by category', 'kind' => 'scores', 'locked' => true,
                'rows' => $weighted->map(fn (array $r) => array_intersect_key($r, array_flip(['code', 'name', 'points', 'max', 'pct', 'band', 'band_label', 'color'])))->values()->all(),
                'overall' => ['points' => $total, 'max' => $available, 'pct' => $overallPct, 'band' => $overallBand->code, 'band_label' => $overallBand->label],
                'sources' => [],
            ],
            $this->strengths($rows, $items),
            $this->risks($rows, $items),
            $this->opportunities($rows, $items),
            ['key' => 'section_c', 'title' => 'SECTION C: DETAILED CATEGORY ANALYSIS', 'kind' => 'heading', 'locked' => true, 'sources' => []],
        ];

        foreach ($rows as $i => $row) {
            $sections[] = $row['code'] === 'property'
                ? $this->property($answers, $name, $i + 1)
                : $this->category($row, $items[$row['code']] ?? [], $answers, $name, $i + 1);
        }

        return ['form_upload_id' => $upload->id, 'sections' => $sections];
    }

    /**
     * The draft as the report check reads a report, so it can be checked before any Word
     * file is made: titles as headings, list points as list items.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public static function asReadReport(array $sections): ReadReport
    {
        $paragraphs = [];
        $add = function (string $text, ?int $heading = null, bool $list = false) use (&$paragraphs): void {
            foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $part) {
                if (trim($part) !== '') {
                    $paragraphs[] = ['text' => trim($part), 'heading' => $heading, 'bold' => $heading !== null, 'list' => $list];
                }
            }
        };

        foreach ($sections as $s) {
            if ($s['key'] === 'title') {
                foreach ($s['lines'] as $line) {
                    $add($line, 1);
                }

                continue;
            }
            $add($s['title'], 1);
            match ($s['kind']) {
                'text' => $add($s['text']),
                'list' => array_map(fn ($item) => $add($item, list: true), $s['items']),
                'scores' => array_map(fn ($r) => $add("{$r['name']}: {$r['points']} of {$r['max']}, {$r['band_label']}, {$r['pct']}%"), $s['rows']),
                'category' => (function () use ($s, $add): void {
                    $add($s['text']);
                    $add('Opportunities for Growth:', 2);
                    array_map(fn ($item) => $add($item, list: true), $s['items']);
                })(),
                default => null,
            };
        }

        return new ReadReport('draft', $paragraphs);
    }

    /** The upload a draft is made from: the approved one when there is one (its scores are the record), otherwise the latest accepted one. */
    public function sourceUpload(Assessment $assessment): ?FormUpload
    {
        $form = $assessment->form()->first();
        $uploads = $form?->uploads()->latest('id')->get() ?? collect();

        return $uploads->first(fn (FormUpload $u) => $u->isApproved())
            ?? ($form?->state !== ArtefactState::RulesFailed ? $uploads->first() : null);
    }

    /**
     * Each category in the form's order: its points, maximum (null when unscored), percentage and band.
     *
     * @return list<Row>
     */
    private function scoreRows(Assessment $assessment, FormUpload $upload): array
    {
        $frozen = $upload->isApproved() ? $assessment->categoryScores()->with('category')->get()
            ->mapWithKeys(fn ($s) => [$s->category->code => (float) $s->points]) : collect();
        $points = $frozen->isNotEmpty() ? $frozen->all()
            : $this->scorer->score($upload->answers ?? [], $upload->form_meta['missing_sheets'] ?? []);

        return Category::query()->orderBy('form_order')->get()->map(function (Category $c) use ($points): array {
            $max = $c->max_points !== null ? (int) $c->max_points : null;
            $got = (float) ($points[$c->code] ?? 0);
            $pct = $max !== null ? round($got * 100 / max(1, $max), 2) : null;
            $band = $pct !== null ? $this->band($pct) : null;

            return ['code' => $c->code, 'name' => $c->name, 'points' => $got, 'max' => $max, 'pct' => $pct,
                'band' => $band?->code, 'band_label' => $band?->label, 'color' => $band?->color];
        })->values()->all();
    }

    private function band(float $pct): HealthBand
    {
        return HealthBand::query()->where('min_pct', '>', 0)->where('min_pct', '<=', max($pct, 0.01))->orderByDesc('min_pct')->firstOrFail();
    }

    /**
     * Every scored question that applies, by category: whether its point was earned, its
     * wording from the bank, and the questions left unanswered.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, list<array{code: string, max: int, earned: bool, unanswered: bool, in_place: string, gap: string, opportunity: string}>>
     */
    private function items(array $answers): array
    {
        $values = $this->values($answers);
        $items = [];

        foreach (FormDefinition::categories() as $category => $definition) {
            foreach ($definition['questions'] as $q) {
                $words = SentenceBank::ITEMS[$q->code] ?? null;
                if ($q->max === null || $words === null || ! $this->applies($q, $answers)) {
                    continue;
                }
                [$inPlace, $gap, $opportunity] = array_map(fn (string $s) => strtr($s, $values), $words);
                $items[$category][] = [
                    'code' => $q->code,
                    'max' => $q->max,
                    'earned' => $q->score($answers) > 0,
                    'unanswered' => $this->unanswered($q, $answers),
                    'in_place' => $inPlace,
                    'gap' => $gap,
                    'opportunity' => $opportunity,
                ];
            }
        }

        return $items;
    }

    /**
     * Whether a scored question speaks to this movement: not answered "Not Applicable",
     * and asked of it (a reserve's length only if it has a reserve, membership criteria
     * only of a federation, interest rates only if it has debt).
     *
     * @param  array<string, mixed>  $answers
     */
    private function applies(Question $q, array $answers): bool
    {
        if (Answer::lower($answers[$q->code] ?? null) === 'not applicable') {
            return false;
        }

        return $q->requiredBecause($answers) !== false || ! $this->unanswered($q, $answers);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function unanswered(Question $q, array $answers): bool
    {
        return match ($q->code) {
            // The income mix is all the shares; the international share is read from Q224.
            'Q214' => array_filter(FormDefinition::INCOME, fn ($c) => Answer::number($answers[$c] ?? null) !== null) === [],
            'Q223' => Answer::number($answers['Q224'] ?? null) === null,
            default => trim((string) ($answers[$q->code] ?? '')) === '',
        };
    }

    /**
     * The movement's own figures, for the bank's {placeholders}.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    private function values(array $answers): array
    {
        $shares = array_filter(array_map(fn ($c) => Answer::number($answers[$c] ?? null), FormDefinition::INCOME), fn ($v) => $v !== null);

        return [
            '{top}' => $shares !== [] ? $this->number(max($shares)) : '[To complete: share]',
            '{international}' => $this->number(Answer::number($answers['Q224'] ?? null)) ?: '[To complete: share]',
            '{restricted}' => $this->number(Answer::number($answers['Q225'] ?? null)) ?: '[To complete: share]',
            '{months}' => $this->number(Answer::number($answers['Q241'] ?? null)) ?: '[To complete: months]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function title(Assessment $assessment): array
    {
        $assessors = $assessment->movement->assessors()->where('active', true)->orderBy('name')->pluck('name')->all();

        return [
            'key' => 'title', 'title' => 'Title', 'kind' => 'title', 'locked' => false,
            'lines' => [
                'ORGANISATIONAL HEALTH ASSESSMENT REPORT',
                $assessment->movement->name.' · '.$assessment->assessed_on->format('F Y'),
                'Report by: '.($assessors !== [] ? $this->sentenceList($assessors).', AAYMCA' : '[To complete: the names of the assessors], AAYMCA'),
            ],
            'sources' => [],
        ];
    }

    /**
     * Three short paragraphs: its legal identity, its structure, and where it is and whom it reaches.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function overview(array $answers, string $name): array
    {
        $text = fn (string $code, string $missing) => ($v = trim((string) ($answers[$code] ?? ''))) !== '' ? $v : "[To complete: {$missing}]";
        $count = fn (string $code) => ($n = Answer::number($answers[$code] ?? null)) !== null ? $this->number($n) : null;

        $legal = $text('Q101', 'its full legal name');
        $identity = "{$legal} is legally registered in ".$text('Q102', 'the country').' as '.$this->article($text('Q107', 'its legal form')).'.';
        $recognitions = array_values(array_filter([
            Answer::isYes($answers['Q106'] ?? null) ? 'is registered as a non-governmental organisation' : null,
            Answer::isYes($answers['Q108'] ?? null) ? 'holds tax-exempt status' : null,
            Answer::isYes($answers['Q109'] ?? null) ? 'has a special status recognised by the government' : null,
            Answer::isYes($answers['Q110'] ?? null) ? 'holds other designations or certifications' : null,
        ]));
        if ($recognitions !== []) {
            $identity .= ' It '.$this->sentenceList($recognitions).'.';
        }

        $structure = match (Answer::lower($answers['Q112'] ?? null)) {
            'single entity' => "{$name} operates as a single legal entity at national level"
                .(($b = $count('Q118')) !== null ? ", with {$b} registered branches or sites." : ', with [To complete: the number of branches or sites].'),
            'federative structure' => "{$name} is a federation of ".($count('Q114') ?? '[To complete: the number of]').' member organisations registered as legal entities'
                .(($g = $count('Q115')) !== null && $g !== '0' ? ", and {$g} informal groups." : '.'),
            '' => "[To complete: how {$name} is structured, and its branches or member organisations.]",
            default => "{$name} is registered as: ".trim((string) $answers['Q112']).'.',
        };

        $reach = array_filter([
            ($n = $count('Q518')) !== null ? "reaches about {$n} people a year" : null,
            ($s = $count('Q1008')) !== null ? "has {$s} staff" : null,
            ($v = $count('Q1013')) !== null ? "{$v} volunteers" : null,
        ]);
        $where = 'Its headquarters are at '.$text('Q104', 'the address').'.'.($reach !== [] ? " It {$this->sentenceList(array_values($reach))}." : '');

        return [
            'key' => 'overview', 'title' => 'ORGANISATIONAL OVERVIEW', 'kind' => 'text', 'locked' => false,
            'text' => implode("\n\n", [$identity, $structure, $where]),
            'sources' => ['Q101', 'Q102', 'Q104', 'Q106', 'Q107', 'Q108', 'Q109', 'Q110', 'Q112', 'Q114', 'Q115', 'Q118', 'Q518', 'Q1008', 'Q1013'],
        ];
    }

    /**
     * About five strengths, from the highest-scoring categories: a headline, then what is in place.
     *
     * @param  list<Row>  $rows
     * @param  array<string, list<array<string, mixed>>>  $items
     * @return array<string, mixed>
     */
    private function strengths(array $rows, array $items): array
    {
        $candidates = collect($rows)->filter(fn ($r) => $r['max'] !== null && $r['pct'] >= 50)->sortByDesc('pct')
            ->map(fn ($r) => [$r, $this->merged(collect($items[$r['code']] ?? [])->where('earned', true)->sortByDesc('max')->pluck('in_place')->all())])
            ->filter(fn ($pair) => $pair[1] !== []);
        // Mainly the Strong and Excellent categories; Developing ones only to make up three.
        $strong = $candidates->filter(fn ($pair) => $pair[0]['pct'] >= 70);
        $points = ($strong->count() >= 3 ? $strong : $candidates->take(max(3, $strong->count())))->take(self::SUMMARY)
            ->map(fn ($pair) => SentenceBank::HEADLINES[$pair[0]['code']]['strength'].': '.$this->sentenceList(array_slice($pair[1], 0, 3)).'.');

        return [
            'key' => 'strengths', 'title' => 'Key Strengths', 'kind' => 'list', 'locked' => false,
            'items' => $points->values()->all() ?: ['[To complete: the movement’s key strengths.]'],
            'sources' => $this->codes($rows, $items, true),
        ];
    }

    /**
     * About five risks, from the lowest-scoring categories: the kind of risk, then what drives it.
     *
     * @param  list<Row>  $rows
     * @param  array<string, list<array<string, mixed>>>  $items
     * @return array<string, mixed>
     */
    private function risks(array $rows, array $items): array
    {
        $points = collect($this->weakest($rows, $items))
            ->map(fn ($pair) => SentenceBank::HEADLINES[$pair[0]['code']]['risk'].': '.$this->sentenceList(array_slice($this->merged($pair[1]->pluck('gap')->all()), 0, 3)).'.');

        return [
            'key' => 'risks', 'title' => 'Key Risks', 'kind' => 'list', 'locked' => false,
            'items' => $points->values()->all() ?: ['[To complete: the movement’s key risks.]'],
            'sources' => $this->codes($rows, $items, false),
        ];
    }

    /**
     * About five opportunities: the first for each of the weakest categories, as in Section C.
     *
     * @param  list<Row>  $rows
     * @param  array<string, list<array<string, mixed>>>  $items
     * @return array<string, mixed>
     */
    private function opportunities(array $rows, array $items): array
    {
        $points = collect($this->weakest($rows, $items))->map(fn ($pair) => $pair[1]->first()['opportunity']);

        return [
            'key' => 'opportunities', 'title' => 'Opportunities for Growth', 'kind' => 'list', 'locked' => false,
            'items' => $points->values()->all() ?: ['Sustain the practices that make the movement strong, and share them with other movements as good practice.'],
            'sources' => $this->codes($rows, $items, false),
        ];
    }

    /**
     * The weakest scored categories that have gaps, lowest first, each with its gaps (the
     * ones worth most points first).
     *
     * @param  list<Row>  $rows
     * @param  array<string, list<array<string, mixed>>>  $items
     * @return list<array{0: Row, 1: Collection<int, array<string, mixed>>}>
     */
    private function weakest(array $rows, array $items): array
    {
        // Unscored categories (Property) have no gaps to weigh, as they have no items.
        $withGaps = collect($rows)->sortBy('pct')
            ->map(fn ($r) => [$r, $this->gaps($r['max'] !== null ? ($items[$r['code']] ?? []) : [])])
            ->filter(fn ($pair) => $pair[1]->isNotEmpty())->values();
        // Mainly the categories below Strong; stronger ones only to make up three.
        $weak = $withGaps->filter(fn ($pair) => $pair[0]['pct'] < 70);

        return ($weak->count() >= 3 ? $weak : $withGaps->take(max(3, $weak->count())))->take(self::SUMMARY)->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function gaps(array $items): Collection
    {
        return collect($items)->where('earned', false)->where('unanswered', false)->sortByDesc('max')->values();
    }

    /**
     * One category's analysis: its score and band, what is in place, the gaps, and its
     * opportunities for growth (the gaps worth most points first).
     *
     * @param  Row  $row
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function category(array $row, array $items, array $answers, string $name, int $number): array
    {
        $inPlace = array_slice($this->merged(collect($items)->where('earned', true)->sortByDesc('max')->pluck('in_place')->all()), 0, 6);
        $gaps = $this->gaps($items);
        $unanswered = collect($items)->where('unanswered', true)->pluck('code')->all();

        $text = "{$name} scored ".$this->number($row['points'])." of {$row['max']} points (".number_format((float) $row['pct'], 2).'%) in '.self::CATEGORY_TITLES[$row['code']].", which is {$row['band_label']}.";
        if ($inPlace !== []) {
            $text .= ' It has '.$this->sentenceList($inPlace).'.';
        }
        if ($gaps->isNotEmpty()) {
            $text .= ($inPlace !== [] ? ' However, it has ' : ' It has ').$this->sentenceList(array_slice($this->merged($gaps->pluck('gap')->all()), 0, 5)).'.';
        }
        if ($row['code'] === 'financial') {
            $text .= $this->finances($answers);
        }
        if ($unanswered !== []) {
            $text .= "\n\n[To complete: the form does not answer ".$this->sentenceList(array_map(fn ($c) => str_replace('#2', ' (2nd)', $c), $unanswered)).'. Confirm these with the movement.]';
        }

        $growth = $gaps->take(5)->pluck('opportunity')->all()
            ?: ['Sustain these practices, and share them with other movements as good practice.'];

        return [
            'key' => 'category:'.$row['code'], 'title' => "{$number}. ".self::CATEGORY_TITLES[$row['code']], 'kind' => 'category', 'locked' => false,
            'category' => $row['code'], 'text' => $text, 'items' => $growth,
            'sources' => array_column($items, 'code'),
        ];
    }

    /**
     * The year's figures, when the form gives them: income, spending and the result.
     *
     * @param  array<string, mixed>  $answers
     */
    private function finances(array $answers): string
    {
        $f = (new Profile($answers))->toArray()['finance'];
        if ($f['income'] === null || $f['expenditure'] === null) {
            return '';
        }
        $currency = $f['currency'] !== null ? $f['currency'].' ' : '';
        $balance = (float) $f['income'] - (float) $f['expenditure'];

        return " In the reporting year its income was {$currency}".number_format((float) $f['income'])
            ." and its expenditure {$currency}".number_format((float) $f['expenditure'])
            .', '.($balance >= 0 ? 'a surplus of ' : 'a deficit of ').$currency.number_format(abs($balance)).'.';
    }

    /**
     * Property Management is not scored: what the movement owns, and the risks to it.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function property(array $answers, string $name, int $number): array
    {
        $owns = Answer::isYes($answers['Q910'] ?? null);
        $listed = trim((string) ($answers['Q911'] ?? ''));
        $risk = trim((string) ($answers['Q914'] ?? ''));

        if (! $owns) {
            $text = Answer::isNo($answers['Q910'] ?? null) ? "{$name} does not own property." : "[To complete: whether {$name} owns property.]";
            $growth = ['Keep any property acquired in future properly registered and documented.'];
        } else {
            $text = "{$name} owns property".($listed !== '' ? ": {$listed}" : '').'.';
            $text .= match (true) {
                Answer::isYes($answers['Q912'] ?? null) => ' Its properties are properly registered and documented.',
                Answer::isNo($answers['Q912'] ?? null) => ' Not all of its properties are properly registered and documented.',
                default => '',
            };
            $growth = array_values(array_filter([
                Answer::isNo($answers['Q912'] ?? null) ? 'Register and document all properties properly.' : null,
                Answer::isNo($answers['Q915'] ?? null) ? 'Develop a property development plan, so the property supports the mission and its income.' : null,
            ]));
        }
        if (Answer::isYes($answers['Q913'] ?? null)) {
            $text .= ' There are risks that could lead to the loss of property'.($risk !== '' ? ": {$risk}" : '').'.';
            $growth[] = 'Address the risks to the property identified in the assessment.';
        }

        return [
            'key' => 'category:property', 'title' => "{$number}. ".self::CATEGORY_TITLES['property'], 'kind' => 'category', 'locked' => false,
            'category' => 'property', 'text' => $text,
            'items' => $growth ?: ['Keep the property registered, documented and planned for as it is now.'],
            'sources' => ['Q910', 'Q911', 'Q912', 'Q913', 'Q914', 'Q915'],
        ];
    }

    /**
     * The questions a summary list draws on.
     *
     * @param  list<Row>  $rows
     * @param  array<string, list<array<string, mixed>>>  $items
     * @return list<string>
     */
    private function codes(array $rows, array $items, bool $earned): array
    {
        return collect($items)->flatten(1)->where('earned', $earned)->where('unanswered', false)->pluck('code')->values()->all();
    }

    /**
     * The strategic plan's participants said once: "a strategic plan shaped with its staff,
     * board members and volunteers" rather than one phrase each; a plain "a strategic plan"
     * goes when the shaped one says it.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function merged(array $phrases): array
    {
        foreach (['a strategic plan shaped with ' => ' and ', 'a strategic plan not shaped with ' => ' or '] as $prefix => $joiner) {
            $who = array_values(array_filter($phrases, fn (string $p) => str_starts_with($p, $prefix)));
            if (count($who) < 2) {
                continue;
            }
            $names = array_map(fn (string $p) => preg_replace('/^its /', '', substr($p, strlen($prefix))), $who);
            $last = array_pop($names);
            $joined = $prefix.'its '.implode(', ', $names).$joiner.$last;
            $at = array_search($who[0], $phrases, true);
            $phrases = array_values(array_filter($phrases, fn (string $p) => ! in_array($p, $who, true)));
            array_splice($phrases, (int) $at, 0, [$joined]);
        }
        if (array_filter($phrases, fn (string $p) => str_starts_with($p, 'a strategic plan shaped with ')) !== []) {
            $phrases = array_values(array_filter($phrases, fn (string $p) => $p !== 'a strategic plan'));
        }

        return $phrases;
    }

    /**
     * "a, b and c".
     *
     * @param  list<string>  $parts
     */
    private function sentenceList(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
    }

    /** "an Association", "a Foundation"; a placeholder stays as it is. */
    private function article(string $noun): string
    {
        if (str_starts_with($noun, '[')) {
            return $noun;
        }

        return (preg_match('/^[aeiou]/i', $noun) ? 'an ' : 'a ').$noun;
    }

    private function number(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
