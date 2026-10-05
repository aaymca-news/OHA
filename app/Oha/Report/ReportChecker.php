<?php

namespace App\Oha\Report;

use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\Movement;
use App\Oha\Finding;
use App\Oha\FindingRule;
use App\Oha\Scorer;

/**
 * Checks an uploaded report against the structure of an OHA analysis report (as in
 * the 2026 Zambia and Zimbabwe reports) and against the movement's own OHA form.
 * It only flags: nothing it finds stops the report going forward.
 *
 * A report is expected to name the movement, the period and its author; give an
 * overview; state the overall score (points out of the form's maximum); list key
 * strengths, risks and opportunities; and analyse every category, each with its
 * opportunities for growth.
 */
final class ReportChecker
{
    /** How each category is recognised in a report's titles. */
    private const CATEGORY_TITLES = [
        'financial' => '/financial\s+(stability|sustainability)/i',
        'governance' => '/\bgovernance\b/i',
        'constitution' => '/constitution|by-?\s?laws|polic(y|ies)\s+(and|&)\s+(procedures|compliance)/i',
        'me' => '/monitoring/i',
        'strategy' => '/strategic\s+planning/i',
        'diversity' => '/diversity|youth\s+participation/i',
        'comms' => '/communications?\b/i',
        'property' => '/property\s+management/i',
        'staff' => '/staff\s*(and|&|\+)\s*volunteer/i',
    ];

    /** The report's own score must be within this many percentage points of the form's. */
    private const TOLERANCE = 0.5;

    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(private readonly Scorer $scorer) {}

    /**
     * @return array{findings: list<Finding>, stated: array{pct: float|null, points: float|null, out_of: float|null}, baseline: array{pct: float, points: float, available: int, source: string}|null}
     */
    public function check(ReadReport $report, Assessment $assessment): array
    {
        $this->findings = [];
        $baseline = $this->baseline($assessment);

        if (! $report->readable()) {
            $this->add(FindingSeverity::Warning, FindingRule::ReportUnreadable,
                $report->problem ?? 'The report has almost no readable text, so it could not be checked.',
                hint: 'Upload the report as a Word (.docx) file, or a PDF saved from Word rather than scanned.');

            return ['findings' => $this->findings, 'stated' => ['pct' => null, 'points' => null, 'out_of' => null], 'baseline' => $baseline];
        }

        $this->checkIdentity($report, $assessment->movement, (int) $assessment->assessed_on->format('Y'));
        $this->checkSections($report);
        $stated = $this->statedScore($report);
        $this->checkScore($stated, $baseline);
        $this->checkCategories($report, $assessment);

        return ['findings' => $this->findings, 'stated' => $stated, 'baseline' => $baseline];
    }

    /**
     * The score the OHA form gives: frozen once the form is approved, otherwise worked
     * out from the latest accepted upload. Null when there is no usable form yet.
     *
     * @return array{pct: float, points: float, available: int, source: string, categories: array<string, float>}|null
     */
    public function baseline(Assessment $assessment): ?array
    {
        $form = $assessment->form()->first();
        $max = Category::query()->weighted()->pluck('max_points', 'code')->map(fn ($v) => (int) $v);

        $scores = $assessment->categoryScores()->with('category')->get();
        if ($scores->isNotEmpty()) {
            $points = $scores->mapWithKeys(fn ($s) => [$s->category->code => (float) $s->points]);
            $available = (int) $scores->sum('max_points_at_scoring');
            $source = 'approved';
        } else {
            $upload = $form?->state !== ArtefactState::RulesFailed ? $form?->currentUpload()->first() : null;
            if ($upload === null) {
                return null;
            }
            $points = collect($this->scorer->score($upload->answers, $upload->form_meta['missing_sheets'] ?? []))
                ->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p);
            $available = (int) $max->sum();
            $source = 'uploaded';
        }

        $total = (float) $points->sum();

        return [
            'pct' => round($total * 100 / max(1, $available), 1),
            'points' => $total,
            'available' => $available,
            'source' => $source,
            'categories' => $points->mapWithKeys(fn ($p, $code) => [$code => round($p * 100 / max(1, $max[$code] ?? 1), 1)])->all(),
        ];
    }

    private function checkIdentity(ReadReport $report, Movement $movement, int $year): void
    {
        $opening = implode(' ', array_column(array_slice($report->paragraphs, 0, 8), 'text'));
        // The title lines: the first few paragraphs that are titles, or short lines naming the report.
        $titleLines = implode(' ', array_column(array_filter(array_slice($report->paragraphs, 0, 6), fn ($p) => ReadReport::isTitle($p)
            || (mb_strlen($p['text']) <= 200 && preg_match('/report|assessment/i', $p['text']) === 1)), 'text'));
        $country = $movement->country;

        if (stripos($report->text(), $country) === false && stripos($report->text(), str_replace(' YMCA', '', $movement->name)) === false) {
            $this->add(FindingSeverity::Warning, FindingRule::MovementMismatch,
                "The report never names {$movement->name}.",
                hint: 'Check this is the right report for this movement.');
        } elseif (stripos($titleLines, $country) === false) {
            $other = Movement::query()->where('id', '!=', $movement->id)->pluck('country')
                ->first(fn ($c) => preg_match('/\b'.preg_quote($c, '/').'\b/i', $titleLines) === 1);
            if ($other !== null) {
                $this->add(FindingSeverity::Warning, FindingRule::MovementMismatch,
                    "The report’s title names {$other}, not {$country}.",
                    hint: 'It may be another movement’s report, or copied from one without changing the title.');
            }
        }

        // The period is in the title lines. Later years are plans ("Vision 2030", "2025–2029"), not the period.
        preg_match_all('/\b(20\d{2})\b/', $titleLines !== '' ? $titleLines : $opening, $m);
        $years = array_values(array_filter(array_map('intval', array_unique($m[1])), fn ($y) => $y <= $year + 1));
        if ($years === []) {
            $this->add(FindingSeverity::Missing, FindingRule::ReportSectionMissing,
                'The report’s title does not give the assessment period.',
                location: 'Title', hint: 'State the month and year of the assessment, e.g. "February 2026".', ref: 'r:period');
        } elseif (! in_array($year, $years, true)) {
            $this->add(FindingSeverity::Warning, FindingRule::ReportPeriodMismatch,
                'The report’s title says '.implode(', ', $years).", but this assessment is for {$year}.",
                location: 'Title', hint: 'Update the period, or check this is the report for this assessment.');
        }

        if (preg_match('/\b(report(ed)?\s+by|prepared\s+by|author)\b/i', $opening.' '.$report->text()) !== 1) {
            $this->add(FindingSeverity::Missing, FindingRule::ReportAuthorMissing,
                'The report does not say who wrote it.',
                location: 'Title', hint: 'Add "Report by:" with the names of the AAYMCA staff who prepared it.', ref: 'r:author');
        }
    }

    private function checkSections(ReadReport $report): void
    {
        $titles = array_column(array_filter($report->paragraphs, [ReadReport::class, 'isTitle']), 'text');
        $has = fn (string $pattern) => array_filter($titles, fn ($t) => preg_match($pattern, $t) === 1) !== [];

        $sections = [
            'overview' => ['/overview|introduction|current\s+status|general\s+information/i', 'an overview of the movement', 'Describe the movement: its registration, structure, branches and reach.'],
            'strengths' => ['/strength/i', 'the key strengths', 'List what the movement does well.'],
            'risks' => ['/\brisks?\b|concern/i', 'the key risks', 'List the risks the assessment found.'],
            'opportunities' => ['/opportunit|priorit/i', 'the opportunities for growth', 'List where the movement can grow; these feed the ODP.'],
        ];

        foreach ($sections as $key => [$pattern, $label, $hint]) {
            if (! $has($pattern)) {
                $this->add(FindingSeverity::Missing, FindingRule::ReportSectionMissing,
                    "The report has no section on {$label}.", hint: $hint, ref: 'r:'.$key);
            }
        }

        if ($report->words() < 400) {
            $this->add(FindingSeverity::Warning, FindingRule::ReportSectionMissing,
                'The report is very short ('.$report->words().' words).', hint: 'Check the whole report was saved into this file.');
        }
    }

    /**
     * The overall score as the report states it: a percentage, and points out of a
     * maximum when it gives them ("52/80").
     *
     * @return array{pct: float|null, points: float|null, out_of: float|null}
     */
    public function statedScore(ReadReport $report): array
    {
        $text = $report->text();
        $pct = $points = $outOf = null;

        if (preg_match('/overall[^.%\n]{0,80}?(\d{1,3}(?:[.,]\d{1,2})?)\s*%/iu', $text, $m)) {
            $pct = (float) str_replace(',', '.', $m[1]);
        }
        if (preg_match('/overall[^.\n]{0,60}?(\d{1,3}(?:\.\d+)?)\s*\/\s*(\d{2,3})\b/iu', $text, $m)) {
            [$points, $outOf] = [(float) $m[1], (float) $m[2]];
            $pct ??= round($points * 100 / $outOf, 1);
        }

        return ['pct' => $pct, 'points' => $points, 'out_of' => $outOf];
    }

    /**
     * @param  array{pct: float|null, points: float|null, out_of: float|null}  $stated
     * @param  array{pct: float, points: float, available: int, source: string, categories: array<string, float>}|null  $baseline
     */
    private function checkScore(array $stated, ?array $baseline): void
    {
        if ($stated['pct'] === null) {
            $this->add(FindingSeverity::Missing, FindingRule::ReportSectionMissing,
                'The report does not state the overall score.',
                hint: $baseline !== null ? 'The OHA form gives '.$this->points($baseline['points']).' of '.$baseline['available'].' points ('.$baseline['pct'].'%).' : 'State the overall score from the OHA form.',
                ref: 'r:score');

            return;
        }

        if ($stated['out_of'] !== null && $baseline !== null && (int) $stated['out_of'] !== $baseline['available']) {
            $this->add(FindingSeverity::Warning, FindingRule::ReportScoreMismatch,
                'The report scores out of '.$this->points($stated['out_of']).'; the OHA form scores out of '.$baseline['available'].'.',
                hint: 'Use the form’s own total: '.$this->points($baseline['points']).' of '.$baseline['available'].'.');
        }

        if ($baseline === null) {
            return;
        }

        if (abs($stated['pct'] - $baseline['pct']) > self::TOLERANCE) {
            $mean = $baseline['categories'] !== [] ? round(array_sum($baseline['categories']) / count($baseline['categories']), 1) : null;
            $averaged = $mean !== null && abs($stated['pct'] - $mean) <= 1.0;

            $this->add(FindingSeverity::Warning, FindingRule::ReportScoreMismatch,
                'The report gives an overall score of '.$this->points($stated['pct']).'%, but the OHA form gives '.$this->points($baseline['points']).' of '.$baseline['available'].' points ('.$baseline['pct'].'%).',
                hint: $averaged
                    ? 'The report’s figure is the average of the category percentages ('.$mean.'%). The form scores points out of '.$baseline['available'].', so the categories weigh differently.'
                    : 'Use the score from the '.($baseline['source'] === 'approved' ? 'approved' : 'uploaded').' OHA form.');
        }
    }

    private function checkCategories(ReadReport $report, Assessment $assessment): void
    {
        $names = Category::query()->pluck('name', 'code');
        $sections = $this->categorySections($report);
        $baseline = $this->baseline($assessment);
        $numbersInText = false;

        foreach (self::CATEGORY_TITLES as $code => $pattern) {
            $name = $names[$code] ?? $code;

            if (! isset($sections[$code])) {
                $this->add(FindingSeverity::Missing, FindingRule::ReportCategoryMissing,
                    "The report has no analysis of {$name}.", location: 'Category analysis',
                    hint: 'Each of the nine categories needs its own short analysis and opportunities for growth.', ref: 'r:cat:'.$code, category: $code);

                continue;
            }

            $body = $sections[$code];
            $hasGrowth = array_filter($body, fn ($p) => preg_match('/opportunit|priorit|recommend/i', $p['text']) === 1) !== [];
            $hasPoints = array_filter($body, fn ($p) => $p['list']) !== [];
            if (! $hasGrowth && ! $hasPoints) {
                $this->add(FindingSeverity::Missing, FindingRule::ReportCategoryMissing,
                    "{$name} has no opportunities for growth.", location: $name,
                    hint: 'List what the movement can do next in this category; the ODP builds on it.', ref: 'r:growth:'.$code, category: $code);
            }

            // A percentage written in the category's own text is checked against the form.
            $text = implode(' ', array_column($body, 'text'));
            // Only a figure worded as the category's score ("scored 55%", "55% score"), not any
            // percentage in the text (a funding share, a youth quota).
            if ($baseline !== null && isset($baseline['categories'][$code])
                && preg_match('/(?:scor\w*|attain\w*|achiev\w*)[^.%\d]{0,30}(\d{1,3}(?:\.\d{1,2})?)\s*%|(\d{1,3}(?:\.\d{1,2})?)\s*%\s*(?:score|in this category)/i', $text, $found)) {
                $m = [1 => $found[1] !== '' ? $found[1] : $found[2]];
                $numbersInText = true;
                if (abs((float) $m[1] - $baseline['categories'][$code]) > 1.0 && (float) $m[1] <= 100) {
                    $this->add(FindingSeverity::Warning, FindingRule::ReportScoreMismatch,
                        "{$name}: the report says {$m[1]}%, the OHA form gives {$baseline['categories'][$code]}%.",
                        location: $name, hint: 'Check the figure against the form.', category: $code);
                }
            }
        }

        if ($report->images > 0 && ! $numbersInText) {
            $this->add(FindingSeverity::Warning, FindingRule::ReportScoreUnreadable,
                'The category scores are only in a picture, so they could not be checked against the form.',
                hint: 'Paste the score table as a Word table (or write each category’s score in its section), so it can be checked.');
        }
    }

    /**
     * Each category's paragraphs: from its title to the next category's title.
     *
     * @return array<string, list<array{text: string, heading: int|null, bold: bool, list: bool}>>
     */
    private function categorySections(ReadReport $report): array
    {
        $sections = [];
        $current = null;

        foreach ($report->paragraphs as $p) {
            $code = ReadReport::isTitle($p) ? $this->categoryNamedBy($p['text']) : null;
            if ($code !== null) {
                $current = $code;
                $sections[$code] ??= [];

                continue;
            }
            if ($current !== null) {
                $sections[$current][] = $p;
            }
        }

        return $sections;
    }

    /**
     * The category a title introduces. "Key strengths…" and other summary titles are
     * not category titles, and a title naming several categories picks the first.
     */
    private function categoryNamedBy(string $title): ?string
    {
        if (preg_match('/^(key|strategic|summary|consolidated|overall)\b/i', $title) === 1) {
            return null;
        }
        foreach (self::CATEGORY_TITLES as $code => $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return $code;
            }
        }

        return null;
    }

    private function points(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function add(FindingSeverity $severity, FindingRule $rule, string $message, string $location = '', string $hint = '', ?string $ref = null, ?string $category = null): void
    {
        $this->findings[] = new Finding($severity, $rule, $message, $location, $hint, $ref, categoryCode: $category);
    }
}
