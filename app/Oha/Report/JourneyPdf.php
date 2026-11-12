<?php

namespace App\Oha\Report;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\HealthBand;
use App\Oha\EvidenceQuestions;
use App\Oha\FollowUpSchedule;
use App\Queries\Journeys;
use App\Queries\OdpProgress;
use App\Support\PdfDocument;
use App\Support\Viz;
use Illuminate\Support\Carbon;

/**
 * The cycle's journey as a PDF to download and share (decided 12 Nov 2026): from Baseline 1
 * (the closed assessment) to the newest baseline. Page 1: the key figures, the growth
 * curve, and where it stands now as three rings (points earned, ODP tasks, "Yes" answers
 * and their evidence). Page 2: each category at each baseline, and Baseline 1 against now.
 * Then what changed at each follow-up, with the reasons, and the "Yes" answers with no
 * evidence yet. Every figure is the baselines' as recorded (Journeys).
 */
final class JourneyPdf
{
    private const INK = '#231f20';

    private const MUTED = '#6b6862';

    private const HAIR = '#e4e2dd';

    private const FAINT = '#efede8';

    private const GOOD = '#0ca30c';

    private const AMBER = '#fab219';

    private const RED = '#d03b3b';

    private const NEUTRAL = '#c9c7c1';

    private const LEFT = 48.0;

    private const WIDTH = 499.28;

    private const BOTTOM = 780.0;

    private PdfDocument $pdf;

    private float $y = 0;

    public function __construct(private readonly Journeys $journeys) {}

    public function build(Assessment $assessment): string
    {
        $steps = $this->journeys->of($assessment);
        if ($steps === []) {
            throw new WorkflowRuleBroken('There is no journey yet: Baseline 1 is recorded when the assessment is closed.');
        }
        $movement = $assessment->movement;
        $this->pdf = new PdfDocument($movement->name.' · the '.$assessment->period_label.' cycle’s journey');

        $this->overview($assessment, $steps);
        $this->categories($steps);
        $this->changes($assessment, $steps);

        $pages = $this->pdf->pageCount();
        for ($i = 0; $i < $pages; $i++) {
            $this->pdf->onPage($i);
            $this->pdf->line(self::LEFT, 806, self::LEFT + self::WIDTH, 806, 0.5, self::HAIR);
            $this->pdf->text(self::LEFT, 820, $movement->name.' · the '.$assessment->period_label.' cycle’s journey · AAYMCA', 8, false, self::MUTED);
            $this->pdf->text(self::LEFT + self::WIDTH, 820, 'Page '.($i + 1).' of '.$pages, 8, false, self::MUTED, 'right');
        }

        return $this->pdf->output();
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function overview(Assessment $assessment, array $steps): void
    {
        $pdf = $this->pdf;
        $pdf->addPage();
        $first = $steps[0];
        $now = $steps[count($steps) - 1];
        $followUps = collect(FollowUpSchedule::for($assessment))->where('state', FollowUpSchedule::LOCKED)->count();

        $pdf->text(self::LEFT, 58, 'AAYMCA · ORGANISATIONAL HEALTH ASSESSMENT', 8, true, self::MUTED);
        $pdf->text(self::LEFT, 88, $assessment->movement->name, 22, true);
        $pdf->text(self::LEFT, 110, 'The '.$assessment->period_label.' cycle’s journey, from Baseline 1 to Baseline '.$now['number'], 12);
        $pdf->text(self::LEFT, 128, 'Prepared '.now()->format('j M Y').'. Scored by the OHA form’s own formulas, as recorded at each baseline.', 9, false, self::MUTED);

        $change = count($steps) > 1 ? round($now['pct'] - $first['pct'], 1) : null;
        $boxes = [
            ['BASELINE 1', Viz::pct($first['pct']), $this->band($first['band']).' · '.Viz::points($first['points'], $first['max'], '/').' points'],
            ['NOW · BASELINE '.$now['number'], Viz::pct($now['pct']), $this->band($now['band']).' · '.Viz::points($now['points'], $now['max'], '/').' points'],
            ['CHANGE', $change === null ? '—' : ($change > 0 ? '+' : '').number_format($change, 1).' pts', 'Percentage points since Baseline 1'],
            ['FOLLOW-UPS CLOSED', $followUps.' of '.(Journeys::slots() - 1), 'ODP tasks done: '.($now['tasks_total'] !== null ? $now['tasks_done'].' of '.$now['tasks_total'] : '—')],
        ];
        $boxWidth = (self::WIDTH - 3 * 10) / 4;
        foreach ($boxes as $i => [$label, $value, $sub]) {
            $x = self::LEFT + $i * ($boxWidth + 10);
            $pdf->rect($x, 146, $boxWidth, 74, '#ffffff', self::HAIR, 0.75);
            $pdf->text($x + 10, 164, $label, 7.5, true, self::MUTED);
            $pdf->text($x + 10, 190, $value, 18, true);
            $pdf->paragraph($x + 10, 205, $boxWidth - 20, $sub, 7.5, false, self::MUTED, 1.25);
        }

        $this->heading(256, 'Growth curve', 'The overall score at each baseline: Baseline 1 when the assessment was closed, then one after each follow-up.');
        $this->growthCurve($steps, 292, 468);

        $this->heading(528, 'Where it stands now · Baseline '.$now['number'], null);
        $tasks = OdpProgress::of($assessment)['summary'];
        $yes = count(array_filter(array_keys(EvidenceQuestions::LABELS), fn (string $code) => strtolower(trim((string) ($now['baseline']->answers[$code] ?? ''))) === 'yes'));
        $unproven = count($now['baseline']->evidence_gaps ?? []);
        $column = self::WIDTH / 3;
        $this->ring(self::LEFT + $column * 0.5, 'Points', Viz::figure($now['points']).' / '.$now['max'], [
            ['Earned', $now['points'], self::INK],
            ['Still open', max(0, $now['max'] - $now['points']), self::HAIR],
        ], 'points');
        $this->ring(self::LEFT + $column * 1.5, 'ODP tasks now', $tasks['done'].' / '.$tasks['total'], [
            ['Done', $tasks['done'], self::GOOD],
            ['In progress', $tasks['in_progress'], self::AMBER],
            ['Blocked', $tasks['blocked'], self::RED],
            ['Not started', max(0, $tasks['total'] - $tasks['done'] - $tasks['in_progress'] - $tasks['blocked']), self::NEUTRAL],
        ], 'tasks');
        $this->ring(self::LEFT + $column * 2.5, '“Yes” answers and evidence', ($yes - $unproven).' / '.$yes, [
            ['With evidence', $yes - $unproven, self::INK],
            ['No evidence yet', $unproven, self::AMBER],
        ], 'answers');
    }

    /**
     * The overall score at each baseline slot, with the health bands as hairlines.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function growthCurve(array $steps, float $top, float $bottom): void
    {
        $pdf = $this->pdf;
        $left = self::LEFT + 30;
        $right = self::LEFT + self::WIDTH - 82;
        $yOf = fn (float $pct) => $bottom - ($bottom - $top) * $pct / 100;
        $slots = Journeys::slots();
        $xOf = fn (int $i) => $left + ($right - $left) * (0.08 + $i * 0.84 / max(1, $slots - 1));

        $pdf->line($left, $top, $right, $top, 0.5, self::FAINT);
        foreach (HealthBand::query()->where('min_pct', '>', 1)->orderBy('min_pct')->get(['label', 'min_pct']) as $band) {
            $y = $yOf((float) $band->min_pct);
            $pdf->line($left, $y, $right, $y, 0.5, self::FAINT);
            $pdf->text($right + 6, $y + 3, $band->label.' '.Viz::figure((float) $band->min_pct).'%', 8, false, self::MUTED);
        }
        $pdf->line($left, $bottom, $right, $bottom, 0.75, self::HAIR);
        $pdf->text($left - 6, $top + 3, '100%', 8, false, self::MUTED, 'right');
        $pdf->text($left - 6, $bottom + 3, '0%', 8, false, self::MUTED, 'right');

        $byNumber = collect($steps)->keyBy('number');
        $points = [];
        for ($i = 0; $i < $slots; $i++) {
            $step = $byNumber[$i + 1] ?? null;
            $x = $xOf($i);
            $pdf->text($x, $bottom + 16, 'Baseline '.($i + 1), 9, true, $step === null ? self::MUTED : self::INK, 'center');
            $pdf->text($x, $bottom + 28, $step === null ? 'not yet' : $step['baseline']->set_at->format('j M Y'), 8, false, self::MUTED, 'center');
            if ($step !== null) {
                $points[] = [$x, $yOf($step['pct']), $step];
            }
        }
        $pdf->polyline(array_map(fn ($p) => [$p[0], $p[1]], $points), 2, self::INK);
        foreach ($points as $i => [$x, $y, $step]) {
            $pdf->circle($x, $y, 6, '#ffffff');
            $pdf->circle($x, $y, 4, self::INK);
            if ($i === 0 || $i === count($points) - 1) {
                $pdf->text($x, $y - 11, Viz::pct($step['pct']), 9, true, self::INK, 'center');
            }
        }
    }

    /**
     * A ring (a pie with a hole), its title above, its figure inside and its legend below:
     * every part named with its count, never told by colour alone.
     *
     * @param  list<array{0: string, 1: int|float, 2: string}>  $parts
     */
    private function ring(float $cx, string $title, string $figure, array $parts, string $unit): void
    {
        $pdf = $this->pdf;
        $cy = 618;
        $total = array_sum(array_column($parts, 1));
        $pdf->text($cx, 560, $title, 10, true, self::INK, 'center');

        if ($total <= 0) {
            $pdf->circle($cx, $cy, 44, self::FAINT);
        } else {
            $angle = 0.0;
            $starts = [];
            foreach ($parts as [, $value, $color]) {
                if ($value <= 0) {
                    continue;
                }
                $sweep = 360 * $value / $total;
                $pdf->slice($cx, $cy, 44, $angle, $angle + $sweep, $color);
                $starts[] = $angle;
                $angle += $sweep;
            }
            // A 2pt gap of white between the parts.
            if (count($starts) > 1) {
                foreach ($starts as $start) {
                    $pdf->line($cx, $cy, $cx + 45 * sin(deg2rad($start)), $cy - 45 * cos(deg2rad($start)), 2, '#ffffff');
                }
            }
        }
        $pdf->circle($cx, $cy, 27, '#ffffff');
        $pdf->text($cx, $cy + 4, $total <= 0 ? 'None' : $figure, 11, true, self::INK, 'center');

        $y = 682;
        foreach ($parts as [$label, $value, $color]) {
            $pdf->rect($cx - 60, $y - 7, 8, 8, $color);
            $pdf->text($cx - 47, $y, $label.': '.Viz::figure((float) $value).($unit === 'points' ? ' '.((float) $value === 1.0 ? 'point' : 'points') : ''), 8.5);
            $y += 13;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function categories(array $steps): void
    {
        $pdf = $this->pdf;
        $pdf->addPage();
        $this->heading(64, 'Each category, baseline by baseline', 'The percentage of each category’s points, as recorded at each baseline.');

        $slots = Journeys::slots();
        $byNumber = collect($steps)->keyBy('number');
        $nameWidth = 178.0;
        $colWidth = (self::WIDTH - $nameWidth - 64) / $slots;
        $right = fn (int $i) => self::LEFT + $nameWidth + ($i + 1) * $colWidth;
        $changeX = self::LEFT + self::WIDTH;

        $y = 112;
        for ($i = 0; $i < $slots; $i++) {
            $pdf->text($right($i), $y, 'Baseline '.($i + 1), 8.5, true, isset($byNumber[$i + 1]) ? self::INK : self::MUTED, 'right');
        }
        $pdf->text($changeX, $y, 'Change', 8.5, true, self::INK, 'right');
        $pdf->text(self::LEFT, $y, 'Category', 8.5, true);
        $pdf->line(self::LEFT, $y + 7, $changeX, $y + 7, 0.75, self::HAIR);

        $first = $steps[0];
        $now = $steps[count($steps) - 1];
        $y += 24;
        foreach ($first['rows'] as $code => $row) {
            $pdf->text(self::LEFT, $y, $row['name'], 9.5);
            for ($i = 0; $i < $slots; $i++) {
                $cell = $byNumber[$i + 1]['rows'][$code] ?? null;
                $pdf->text($right($i), $y, $cell === null ? '—' : ($cell['pct'] === null ? 'Not weighted' : number_format($cell['pct'], 1).'%'), 9.5, false, $cell === null || $cell['pct'] === null ? self::MUTED : self::INK, 'right');
            }
            $from = $row['pct'];
            $to = $now['rows'][$code]['pct'] ?? null;
            $pdf->text($changeX, $y, $from === null || $to === null || count($steps) === 1 ? '—' : $this->signed($to - $from), 9.5, false, self::INK, 'right');
            $pdf->line(self::LEFT, $y + 7, $changeX, $y + 7, 0.5, self::FAINT);
            $y += 20;
        }
        $pdf->text(self::LEFT, $y, 'Total', 9.5, true);
        for ($i = 0; $i < $slots; $i++) {
            $step = $byNumber[$i + 1] ?? null;
            $pdf->text($right($i), $y, $step === null ? '—' : number_format($step['pct'], 1).'%', 9.5, true, $step === null ? self::MUTED : self::INK, 'right');
            $pdf->text($right($i), $y + 12, $step === null ? '' : $this->band($step['band']), 8, false, self::MUTED, 'right');
        }
        $pdf->text($changeX, $y, count($steps) === 1 ? '—' : $this->signed($now['pct'] - $first['pct']), 9.5, true, self::INK, 'right');
        $y += 30;
        foreach ([
            ['ODP tasks done', fn ($s) => $s['tasks_total'] !== null ? $s['tasks_done'].' of '.$s['tasks_total'] : '—'],
            ['Evidence counted', fn ($s) => (string) $s['evidence']],
            ['Answers changed', fn ($s) => $s['number'] === 1 ? '—' : (string) $s['changed']],
        ] as [$label, $value]) {
            $pdf->line(self::LEFT, $y - 12, $changeX, $y - 12, 0.5, self::FAINT);
            $pdf->text(self::LEFT, $y, $label, 9, false, self::MUTED);
            for ($i = 0; $i < $slots; $i++) {
                $step = $byNumber[$i + 1] ?? null;
                $pdf->text($right($i), $y, $step === null ? '—' : $value($step), 9, false, self::MUTED, 'right');
            }
            $y += 18;
        }

        // Baseline 1 against now, category by category.
        if (count($steps) > 1) {
            $y += 30;
            $this->heading($y, 'Each category: Baseline 1 and now', 'Hollow: Baseline 1. Filled: Baseline '.$now['number'].', the newest.');
            $y += 50;
            $trackLeft = self::LEFT + 170;
            $trackRight = self::LEFT + self::WIDTH - 80;
            $xOf = fn (float $pct) => $trackLeft + ($trackRight - $trackLeft) * $pct / 100;
            foreach ($first['rows'] as $code => $row) {
                $to = $now['rows'][$code]['pct'] ?? null;
                if ($row['pct'] === null || $to === null) {
                    continue;
                }
                $pdf->text(self::LEFT, $y + 3, $row['name'], 9);
                $pdf->line($trackLeft, $y, $trackRight, $y, 0.5, self::HAIR);
                $pdf->line($xOf($row['pct']), $y, $xOf($to), $y, 2, self::INK);
                $pdf->circle($xOf($row['pct']), $y, 4, '#ffffff', self::INK, 1.5);
                $pdf->circle($xOf($to), $y, 6, '#ffffff');
                $pdf->circle($xOf($to), $y, 4, self::INK);
                $pdf->text(self::LEFT + self::WIDTH, $y + 3, number_format($row['pct'], 0).'% -> '.number_format($to, 0).'%', 8.5, false, self::INK, 'right');
                $y += 22;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function changes(Assessment $assessment, array $steps): void
    {
        $pdf = $this->pdf;
        $pdf->addPage();
        $wording = $this->journeys->wording($assessment);
        $this->heading(64, 'What changed at each follow-up', 'Each answer changed since the baseline before, with the reason given, who changed it and how.');
        $this->y = 112;

        if (count($steps) === 1) {
            $this->y = $pdf->paragraph(self::LEFT, $this->y, self::WIDTH, 'No follow-up is closed yet: Baseline 1 is the only baseline so far.', 9.5, false, self::MUTED);
        }
        foreach (array_slice($steps, 1) as $index => $step) {
            $before = $steps[$index];
            $baseline = $step['baseline'];
            $this->room(40);
            $pdf->text(self::LEFT, $this->y, 'Baseline '.$step['number'].' · after follow-up '.$baseline->follow_up_number, 11, true);
            $this->y += 14;
            $this->y = $pdf->paragraph(self::LEFT, $this->y, self::WIDTH,
                'Recorded '.$baseline->set_at->format('j M Y').($baseline->setter ? ' by '.$baseline->setter->name : '').' · '
                .Viz::pct($before['pct']).' -> '.Viz::pct($step['pct']).' · '.$step['changed'].' '.($step['changed'] === 1 ? 'answer' : 'answers').' changed', 8.5, false, self::MUTED);
            $this->y += 4;
            foreach ($baseline->changes ?? [] as $code => $change) {
                $question = str_replace('#2', ' (2nd)', $code).'  '.mb_strimwidth($wording[$code] ?? '', 0, 110, '…');
                $detail = $this->shown($change['from'] ?? null).' -> '.$this->shown($change['to'] ?? null)
                    .' · “'.($change['reason'] ?? '').'” · '.($change['by_name'] ?? '').', '.Carbon::parse($change['at'] ?? now())->format('j M Y')
                    .' · '.(($change['via'] ?? '') === 'form' ? 'from the re-uploaded form' : 'changed on the site');
                $lines = count($pdf->wrap($question, self::WIDTH, 9, true)) + count($pdf->wrap($detail, self::WIDTH - 10, 8.5));
                $this->room(14 * $lines + 6);
                $this->y = $pdf->paragraph(self::LEFT, $this->y, self::WIDTH, $question, 9, true, self::INK, 1.3);
                $this->y = $pdf->paragraph(self::LEFT + 10, $this->y, self::WIDTH - 10, $detail, 8.5, false, self::MUTED, 1.3);
                $this->y += 5;
            }
            if (($baseline->changes ?? []) === []) {
                $this->y = $pdf->paragraph(self::LEFT, $this->y, self::WIDTH, 'No answer changed: the score stayed as it was.', 9, false, self::MUTED);
            }
            $this->y += 12;
        }

        $now = $steps[count($steps) - 1];
        $gaps = $now['baseline']->evidence_gaps ?? [];
        $this->room(60);
        $this->y += 8;
        $pdf->text(self::LEFT, $this->y, '“Yes” answers with no evidence yet · Baseline '.$now['number'], 11, true);
        $this->y += 16;
        $text = $gaps === []
            ? 'Every “Yes” that evidence can prove has evidence counted.'
            : 'They still score, as on the Excel form, but nothing proves them yet: '
                .implode('; ', array_map(fn (string $code) => EvidenceQuestions::shown($code).' '.EvidenceQuestions::label($code), $gaps)).'.';
        $this->room(14 * count($pdf->wrap($text, self::WIDTH, 9)));
        $this->y = $pdf->paragraph(self::LEFT, $this->y, self::WIDTH, $text, 9);

        $note = 'How the scores are worked out: each baseline is scored from its answers by the OHA form’s own formulas, exactly as the Excel form scores them. “Not Applicable” scores 0, with the category’s maximum unchanged. A baseline is never changed once recorded; when a follow-up is reopened, its baseline is set aside and kept.';
        $this->room(14 * count($pdf->wrap($note, self::WIDTH, 8.5)) + 20);
        $this->y += 18;
        $pdf->paragraph(self::LEFT, $this->y, self::WIDTH, $note, 8.5, false, self::MUTED);
    }

    private function heading(float $y, string $title, ?string $subtitle): void
    {
        $this->pdf->text(self::LEFT, $y, $title, 13, true);
        if ($subtitle !== null) {
            $this->pdf->paragraph(self::LEFT, $y + 16, self::WIDTH, $subtitle, 9, false, self::MUTED);
        }
    }

    /** A new page when $height does not fit below the current line. */
    private function room(float $height): void
    {
        if ($this->y + $height > self::BOTTOM) {
            $this->pdf->addPage();
            $this->y = 64;
        }
    }

    private function band(?HealthBand $band): string
    {
        return $band->label ?? '';
    }

    private function signed(float $delta): string
    {
        $delta = round($delta, 1);

        return ($delta > 0 ? '+' : '').number_format($delta, 1);
    }

    private function shown(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => 'blank',
            is_int($value) || is_float($value) => Viz::figure($value, 6),
            default => '“'.mb_strimwidth((string) $value, 0, 80, '…').'”',
        };
    }
}
