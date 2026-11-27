<?php

namespace App\Oha\Report;

use App\Models\HealthBand;
use App\Models\Movement;
use App\Models\Zone;
use App\Queries\DashboardAnalytics;
use App\Support\Bands;
use App\Support\PdfDocument;
use App\Support\Radar;
use App\Support\RedFlags;
use App\Support\Viz;

/**
 * The dashboards as a PDF to download and share (decided 23 Nov 2026): the same figures as
 * the screen, from App\Queries\DashboardAnalytics, laid out for a board pack.
 *
 *   movement  the key figures, the health shape, the score over the cycle with the pace to
 *             the next band, each area, money and people, the policies (its own figures only)
 *   region    the key figures, who needs support, from baseline to now, the bands then and
 *             now, the region's health shape, red flags and the points most often missed
 *
 * Drawn with App\Support\PdfDocument, as the journey report is: A4, Helvetica, nothing installed.
 */
final class DashboardPdf
{
    private const INK = '#231f20';

    private const MUTED = '#6b6862';

    private const HAIR = '#e4e2dd';

    private const FAINT = '#efede8';

    private const BLUE = '#2f6fb0';

    private const RED = '#c8102e';

    private const ORANGE = '#d9822b';

    private const GOOD = '#065f06';

    private const LEFT = 48.0;

    private const WIDTH = 499.28;

    private const BOTTOM = 780.0;

    private PdfDocument $pdf;

    private float $y = 0;

    /**
     * A movement's dashboard: its own figures only, no regional comparison (27 Nov 2026).
     *
     * @param  array<string, mixed>  $d  from DashboardAnalytics::movement()
     */
    public function movement(Movement $movement, array $d): string
    {
        $title = $movement->name.' · OHA dashboard';
        $this->pdf = new PdfDocument($title);
        $this->pdf->addPage();
        $this->masthead($movement->name, 'Dashboard: its health now, over the cycle, and where it can still grow');

        $journey = $d['journey'];
        $this->boxes(148, [
            ['CURRENT SCORE', Viz::pct($d['pct']), $this->bandName($d['band'])],
            ['SINCE BASELINE', $d['followed'] ? Viz::signed($journey['change']).' pts' : '—', $journey ? 'Baseline '.Viz::pct($journey['first']) : 'Not closed yet'],
            ['POINTS EARNED', Viz::figure($d['points']['earned']).' / '.$d['points']['max'], Viz::figure($d['points']['max'] - $d['points']['earned']).' still open'],
            [count($d['weakest']) > 1 ? 'WEAKEST AREAS' : 'WEAKEST AREA', Viz::tiedFigure(array_column($d['weakest'], 'short')), count($d['weakest']) > 1
                ? 'All at '.Viz::pct($d['weakest_pct'], 0).': '.Viz::names(array_column($d['weakest'], 'short'))
                : 'At '.Viz::pct($d['weakest'][0]['pct'], 0).' · '.Viz::figure($d['weakest'][0]['lost']).' of '.$d['weakest'][0]['max'].' points open'],
        ]);

        // The health shape | the score over the cycle
        $this->heading(256, 'Health shape', null);
        $shape = $d['shape'];
        $series = [];
        if ($d['followed']) {
            $series[] = ['When the cycle began', array_column($shape, 'baseline'), self::ORANGE, 1.4];
        }
        $series[] = ['Now', array_column($shape, 'pct'), self::BLUE, 2];
        $this->octagon(self::LEFT + 112, 392, 88, array_column($shape, 'short'), $series);

        $this->pdf->text(self::LEFT + 262, 256, 'Score over the cycle', 13, true);
        if ($journey) {
            $this->curve(self::LEFT + 276, self::LEFT + self::WIDTH - 46, 282, 448, $d['curve'], $d['pace']['points'] ?? null);
            if ($d['pace']) {
                $p = $d['pace'];
                $line = 'Pace to '.$p['band'].': about '.Viz::signed($p['need']).' points at each of the '.$p['left'].' follow-up'.($p['left'] === 1 ? '' : 's').' left'
                    .($p['gained'] !== null ? '; '.Viz::signed($p['gained']).' per follow-up so far ('.($p['on_course'] ? 'on course' : 'needs to speed up').').' : '.');
                $this->pdf->paragraph(self::LEFT + 262, 498, self::WIDTH - 262, $line, 8.5, false, self::RED);
            }
        } else {
            $this->pdf->paragraph(self::LEFT + 262, 282, self::WIDTH - 262, 'The baseline is set when the assessment is closed; each follow-up then adds a score.', 9, false, self::MUTED);
        }

        // Each area
        $this->y = 540;
        $this->heading($this->y, 'Each area', 'Points earned of each area’s maximum, now and at the baseline. Weakest first.');
        $this->y += 40;
        $this->table([['Area', 190, 'left'], ['Points', 80, 'right'], ['Now', 70, 'right'], ['Baseline', 75, 'right'], ['Points open', 84.28, 'right']],
            array_map(fn (array $a) => [$a['name'], Viz::figure($a['points']).' / '.$a['max'], Viz::pct($a['pct'], 0), $a['baseline'] !== null ? Viz::pct($a['baseline'], 0) : '—',
                $a['lost'] > 0 ? Viz::figure($a['lost']) : 'none'], $d['areas']));

        // Money and people
        $money = $d['money'];
        $people = $d['people'];
        $pct = fn (?float $v) => $v === null ? '—' : Viz::number($v).'%';
        $this->room(120);
        $this->y += 18;
        $this->heading($this->y, 'Money and people', 'From the form as it stands, the last financial year.');
        $this->y += 40;
        $this->table([['Figure', 330, 'left'], ['Value', 169.28, 'right']], [
            ['International funding (the form’s line: '.$money['limit'].'%)', $pct($money['international'])],
            ['Restricted funding', $pct($money['restricted'])],
            ['Operating margin', $money['margin'] === null ? '—' : ($money['margin'] > 0 ? '+' : '').Viz::number($money['margin'], 1).'%'],
            ['Cash reserve', $money['reserve_months'] !== null ? Viz::number($money['reserve_months']).' months' : 'None'],
            ['Staff', Viz::number($people['staff'])],
            ['Volunteers', Viz::number($people['volunteers'])],
            ['Board members', Viz::number($people['board'])],
            ['Women on the Board', $people['women'] !== null ? $people['women'].'%' : '—'],
            ['Under 30 on the Board', $people['under_30'] !== null ? $people['under_30'].'%' : '—'],
        ]);

        // Policies
        $this->room(140);
        $this->y += 18;
        $this->heading($this->y, 'Policies in place: '.$people['policies'].' of '.$people['policies_of'], 'The policies the form asks about.');
        $this->y += 40;
        $this->table([['Policy', 330, 'left'], ['In place', 169.28, 'left']],
            array_map(fn (array $p) => [$p['label'], $p['ours'] === true ? 'In place' : ($p['ours'] === false ? 'Not yet' : 'Not answered')], $d['policies']));

        return $this->finish($title);
    }

    /**
     * AAYMCA's dashboard: the region, or one zone.
     *
     * @param  array<string, mixed>  $d  from DashboardAnalytics::region()
     */
    public function region(?Zone $zone, array $d): string
    {
        $where = $zone ? $zone->name : 'The region';
        $title = 'AAYMCA · OHA dashboard · '.$where;
        $this->pdf = new PdfDocument($title);
        $this->pdf->addPage();
        $this->masthead($where, 'Dashboard: the movements’ health, how it is changing, and who needs support');

        $f = $d['figures'];
        $this->boxes(148, [
            ['ASSESSED', $f['assessed'].' / '.$f['total'], ($f['total'] - $f['assessed']).' not assessed yet'],
            ['AVERAGE SCORE', $f['mean'] !== null ? Viz::pct($f['mean']) : '—', $f['mean_change'] !== null ? Viz::signed($f['mean_change']).' pts since baseline' : 'No follow-up yet'],
            ['IMPROVING', $f['improved'].' of '.$f['followed'], $f['band_up'].' moved up a band'],
            ['AT RISK OR CRITICAL', (string) $f['at_risk'], $f['at_risk_baseline'].' at baseline'],
            ['BACKED BY EVIDENCE', $f['evidence'] !== null ? $f['evidence'].'%' : '—', 'of provable “Yes” answers'],
        ]);

        $this->heading(256, 'Who needs support', 'Each movement by its current score (across) and its points since baseline (up). Not followed up yet: in the lane beneath.');
        $this->matrix($d['matrix'], 300, 236);

        // The key, grouped, just beneath the chart
        $this->y = 300 + 236 + 34;
        $points = $d['matrix']['points'];
        foreach (DashboardAnalytics::SUPPORT_GROUPS as $g => $name) {
            $items = array_filter($points, fn (array $p) => $p['group'] === $g);
            if ($items === []) {
                continue;
            }
            $this->room(30);
            $this->pdf->text(self::LEFT, $this->y, mb_strtoupper($name).' · '.count($items), 8, true, self::MUTED);
            $this->y = $this->pdf->paragraph(self::LEFT, $this->y + 13, self::WIDTH,
                implode(';  ', array_map(fn (array $p) => $p['n'].'. '.$p['movement']->name.' '.Viz::pct($p['pct']).($p['change'] !== null ? ' ('.Viz::signed($p['change']).')' : ''), $items)), 8.5) + 6;
        }

        // From baseline to now, and the bands
        $this->pdf->addPage();
        $this->y = 64;
        if ($d['gains'] !== []) {
            $this->heading($this->y, 'From baseline to now', 'Each movement followed up, biggest gain first.');
            $this->y += 40;
            $this->table([['Movement', 200, 'left'], ['Baseline', 80, 'right'], ['Now', 80, 'right'], ['Change', 70, 'right'], ['Band now', 69.28, 'right']],
                array_map(fn (array $m) => [$m['movement']->name, Viz::pct($m['journey']['first']), Viz::pct($m['journey']['now']), Viz::signed($m['change']), $this->bandName($m['band'])], $d['gains']));
            $this->y += 18;
        }
        $this->room(120);
        $this->heading($this->y, 'Band movement', 'Movements with a baseline, in each band at their baseline and now.');
        $this->y += 40;
        $this->table([['Band', 250, 'left'], ['At baseline', 120, 'right'], ['Now', 129.28, 'right']],
            array_map(fn (array $b) => [$b['band']->label, (string) $b['baseline'], (string) $b['now']], $d['bands']));

        // The region's health shape
        $shape = $d['shape'];
        $this->room(340);
        $this->y += 18;
        $this->heading($this->y, 'The region’s health shape', 'Each area’s median now (blue) and at the baseline (orange).');
        $this->octagon(self::LEFT + self::WIDTH / 2, $this->y + 160, 96, array_column($shape['areas'], 'short'), [
            ['Median at baseline', $shape['baseline'], self::ORANGE, 1.4],
            ['Median now', $shape['now'], self::BLUE, 2],
        ]);
        // Beneath it, every value drawn, zone by zone too.
        $zones = count($shape['zones']) > 1 ? $shape['zones'] : [];
        $rest = (self::WIDTH - 130) / (2 + count($zones));
        $columns = [['Area', 130, 'left'], ['Baseline', $rest, 'right'], ['Now', $rest, 'right']];
        foreach ($zones as $z) {
            $columns[] = [$z['name'], $rest, 'right'];
        }
        $rows = [];
        foreach ($shape['areas'] as $k => $area) {
            $rows[] = [$area['short'], $this->pctOrDash($shape['baseline'][$k]), $this->pctOrDash($shape['now'][$k]), ...array_map(fn ($z) => $this->pctOrDash($z['values'][$k]), $zones)];
        }
        $this->y += 318;
        $this->table($columns, $rows);
        $this->y += 18;

        // Red flags
        $this->room(140);
        $this->heading($this->y, 'Red flags', 'Early warnings, most flags first. Yes: flagged; – : cannot tell yet.');
        $this->y += 40;
        $checks = RedFlags::CHECKS;
        $this->table([['Movement', 139.28, 'left'], ...array_map(fn ($c) => [$c['short'], 66, 'center'], array_values($checks)), ['Flags', 30, 'right']],
            array_map(fn (array $r) => [$r['movement']->name, ...array_map(fn ($key) => $r['checks'][$key] === null ? '–' : ($r['checks'][$key] ? 'Yes' : ''), array_keys($checks)), (string) $r['count']], $d['flags']));

        // Most often missed
        if ($d['missed'] !== []) {
            $this->room(120);
            $this->y += 18;
            $this->heading($this->y, 'Most often missed', 'The scored points most movements still lose: a guide for region-wide support.');
            $this->y += 40;
            $this->table([['Point', 300, 'left'], ['Area', 100, 'left'], ['Missed by', 99.28, 'right']],
                array_map(fn (array $m) => [$m['name'], $m['category'], $m['missed'].' of '.$m['of']], $d['missed']));
        }

        return $this->finish($title);
    }

    private function masthead(string $name, string $line): void
    {
        $this->pdf->text(self::LEFT, 58, 'AAYMCA · ORGANISATIONAL HEALTH ASSESSMENT', 8, true, self::MUTED);
        $this->pdf->text(self::LEFT, 88, $name, 22, true);
        $this->pdf->text(self::LEFT, 110, $line, 12);
        $this->pdf->text(self::LEFT, 128, 'Prepared '.now()->format('j M Y').' from the scores as recorded (approved OHA forms, the baseline and each closed follow-up).', 9, false, self::MUTED);
    }

    /**
     * The key figures, as boxes across the page.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $boxes
     */
    private function boxes(float $top, array $boxes): void
    {
        $gap = 10;
        $width = (self::WIDTH - $gap * (count($boxes) - 1)) / count($boxes);
        foreach ($boxes as $i => [$label, $value, $sub]) {
            $x = self::LEFT + $i * ($width + $gap);
            $this->pdf->rect($x, $top, $width, 78, '#ffffff', self::HAIR, 0.75);
            $this->pdf->text($x + 9, $top + 17, $label, 7, true, self::MUTED);
            $this->pdf->text($x + 9, $top + 43, $value, count($boxes) > 4 ? 15 : 17, true);
            $this->pdf->paragraph($x + 9, $top + 58, $width - 18, $sub, 7.5, false, self::MUTED, 1.25);
        }
    }

    /**
     * An octagon (one corner per area), its rings where the bands begin, and each series as an
     * outline; the legend beneath names every series.
     *
     * @param  list<string>  $names
     * @param  list<array{0: string, 1: list<float|null>, 2: string, 3: float}>  $series
     */
    private function octagon(float $cx, float $cy, float $r, array $names, array $series): void
    {
        $n = count($names);
        $ring = fn (float $pct) => array_map(fn (int $k) => Radar::point($k, $n, $pct / 100 * $r, $cx, $cy), range(0, $n));
        foreach (Radar::RINGS as $pct) {
            $this->pdf->polyline($ring($pct), $pct === 100 ? 0.75 : 0.5, $pct === 100 ? self::HAIR : self::FAINT);
        }
        for ($k = 0; $k < $n; $k++) {
            [$x, $y] = Radar::point($k, $n, $r, $cx, $cy);
            $this->pdf->line($cx, $cy, $x, $y, 0.4, self::FAINT);
            [$lx, $ly] = Radar::point($k, $n, $r + 14, $cx, $cy);
            $cos = cos(deg2rad(-90 + $k * 360 / $n));
            $this->pdf->text($lx, $ly + 3, $names[$k], 7.5, true, self::INK, $cos > 0.3 ? 'left' : ($cos < -0.3 ? 'right' : 'center'));
        }
        foreach ($series as [, $values, $color, $width]) {
            $points = array_map(fn (int $k) => Radar::point($k % $n, $n, max(0, (float) ($values[$k % $n] ?? 0)) / 100 * $r, $cx, $cy), range(0, $n));
            $this->pdf->polyline($points, $width, $color);
        }
        // The rings' percentages last, each on a white patch, so no line runs through them.
        foreach ([50, 70, 90] as $pct) {
            $label = $pct.'%';
            $y = $cy - $pct / 100 * $r - 2;
            $this->pdf->rect($cx + 2, $y - 5.5, $this->pdf->width($label, 6) + 2, 7, '#ffffff');
            $this->pdf->text($cx + 3, $y, $label, 6, false, self::MUTED);
        }
        $x = $cx - $r;
        foreach ($series as [$label, , $color, $width]) {
            $this->pdf->line($x, $cy + $r + 30, $x + 14, $cy + $r + 30, max(1.5, $width), $color);
            $this->pdf->text($x + 18, $cy + $r + 33, $label, 7.5, false, self::MUTED);
            $x += $this->pdf->width($label, 7.5) + 34;
        }
    }

    /**
     * The score over the cycle: the bands as hairlines, the scores, and the pace to the next
     * band (dashed, red).
     *
     * @param  list<array{label: string, pct: float|null, note: string}>  $points
     * @param  list<float|null>|null  $pace
     */
    private function curve(float $left, float $right, float $top, float $bottom, array $points, ?array $pace): void
    {
        $pdf = $this->pdf;
        $slots = count($points);
        $yOf = fn (float $pct) => $bottom - ($bottom - $top) * $pct / 100;
        $xOf = fn (int $i) => $left + ($right - $left) * (0.06 + $i * 0.88 / max(1, $slots - 1));

        $pdf->line($left, $top, $right, $top, 0.5, self::FAINT);
        foreach (collect(Bands::scale())->filter(fn ($b) => $b['from'] > 1) as $b) {
            $pdf->line($left, $yOf($b['from']), $right, $yOf($b['from']), 0.5, self::FAINT);
            $pdf->text($right + 4, $yOf($b['from']) + 2.5, $b['label'], 6.5, false, self::MUTED);
        }
        $pdf->line($left, $bottom, $right, $bottom, 0.75, self::HAIR);
        $pdf->text($left - 4, $top + 3, '100%', 7, false, self::MUTED, 'right');
        $pdf->text($left - 4, $bottom + 3, '0%', 7, false, self::MUTED, 'right');

        if ($pace !== null) {
            $line = [];
            foreach ($pace as $i => $p) {
                if ($p !== null) {
                    $line[] = [$xOf($i), $yOf((float) $p)];
                }
            }
            for ($i = 1; $i < count($line); $i++) {
                $this->dashed($line[$i - 1][0], $line[$i - 1][1], $line[$i][0], $line[$i][1], self::RED, 4, 3, 1.4);
            }
            foreach ($pace as $i => $p) {
                if ($p !== null && $points[$i]['pct'] === null) {
                    $pdf->circle($xOf($i), $yOf((float) $p), 3, '#ffffff', self::RED, 1.2);
                    $pdf->text($xOf($i), $yOf((float) $p) - 7, Viz::pct((float) $p), 6.5, true, self::RED, 'center');
                }
            }
        }

        $reached = [];
        foreach ($points as $i => $point) {
            // "Follow-up 1" on one line, its number beneath for the follow-ups, so the names never run together.
            $name = str_replace(' score', '', $point['label']);
            $color = $point['pct'] === null ? self::MUTED : self::INK;
            if (str_starts_with($name, 'Follow-up ')) {
                $pdf->text($xOf($i), $bottom + 12, 'Follow-up', 6.5, true, $color, 'center');
                $pdf->text($xOf($i), $bottom + 21, substr($name, 10), 6.5, true, $color, 'center');
            } else {
                $pdf->text($xOf($i), $bottom + 12, $name, 6.5, true, $color, 'center');
            }
            if ($point['pct'] !== null) {
                $reached[] = [$xOf($i), $yOf((float) $point['pct']), (float) $point['pct']];
            }
        }
        if (count($reached) > 1) {
            $pdf->polyline(array_map(fn ($p) => [$p[0], $p[1]], $reached), 1.8, self::INK);
        }
        foreach ($reached as [$x, $y, $pct]) {
            $pdf->circle($x, $y, 3.2, self::INK);
            $pdf->text($x, $y - 7, Viz::pct($pct), 7, true, self::INK, 'center');
        }
    }

    /**
     * Who needs support: the four groups as washes, each movement a numbered dot, and the
     * lane beneath for those not followed up yet.
     *
     * @param  array<string, mixed>  $matrix
     */
    private function matrix(array $matrix, float $top, float $height): void
    {
        $pdf = $this->pdf;
        $left = self::LEFT + 26;
        $width = self::WIDTH - 26;
        $plot = $height - 40;
        $splitX = $left + $width * $matrix['x']['split_at'] / 100;
        $zeroY = $top + $plot * $matrix['y']['zero_at'] / 100;

        $pdf->rect($left, $top, $splitX - $left, $zeroY - $top, '#e8f0fb');
        $pdf->rect($splitX, $top, $left + $width - $splitX, $zeroY - $top, '#e6f6e6');
        $pdf->rect($left, $zeroY, $splitX - $left, $top + $plot - $zeroY, '#fdeee7');
        $pdf->rect($splitX, $zeroY, $left + $width - $splitX, $top + $plot - $zeroY, '#f1f1ef');
        $pdf->line($splitX, $top, $splitX, $top + $plot, 0.6, '#808285');
        $pdf->line($left, $zeroY, $left + $width, $zeroY, 0.6, '#808285');
        $pdf->text($left + 6, $top + 11, 'IMPROVING, NOT YET STRONG', 6.5, true, '#1d4f91');
        $pdf->text($left + $width - 6, $top + 11, 'STRONG AND IMPROVING', 6.5, true, self::GOOD, 'right');
        $pdf->text($left + 6, $top + $plot - 6, 'NEEDS SUPPORT', 6.5, true, '#8c3d18');
        $pdf->text($left + $width - 6, $top + $plot - 6, 'STRONG, NOT IMPROVING', 6.5, true, '#4f4e4a', 'right');
        foreach ($matrix['y']['ticks'] as $t) {
            $y = $top + $plot * ($matrix['y']['to'] - $t) / ($matrix['y']['to'] - $matrix['y']['from']);
            $pdf->text($left - 4, $y + 2.5, ($t > 0 ? '+' : '').$t, 7, false, self::MUTED, 'right');
        }
        $laneTop = $top + $plot + 8;
        $pdf->rect($left, $laneTop, $width, 22, '#faf9f6', '#dcd8d0', 0.5);
        $pdf->text($left + $width - 6, $laneTop + 14, 'NO FOLLOW-UP YET', 6.5, true, self::MUTED, 'right');
        foreach ($matrix['x']['ticks'] as $p) {
            $x = $left + $width * ($p - $matrix['x']['from']) / (100 - $matrix['x']['from']);
            $pdf->text($x, $laneTop + 34, $p.'%'.($p == $matrix['x']['split'] ? ' · Strong' : ''), 7, $p == $matrix['x']['split'], self::MUTED, 'center');
        }

        foreach ($matrix['points'] as $p) {
            $x = $left + $width * $p['left'] / 100;
            $y = $p['top'] === null ? $laneTop + 11 : $top + $plot * $p['top'] / 100;
            $followed = $p['change'] !== null;
            $pdf->circle($x, $y, 7, $followed ? self::INK : '#ffffff', self::INK, 1);
            $pdf->text($x, $y + 2.5, (string) $p['n'], 6.5, true, $followed ? '#ffffff' : self::INK, 'center');
        }
    }

    /**
     * A table at the current line, onto a new page when it runs out of room (the header again).
     *
     * @param  list<array{0: string, 1: float, 2: string}>  $columns  title, width, alignment
     * @param  list<list<string>>  $rows
     */
    private function table(array $columns, array $rows, float $left = self::LEFT): void
    {
        $pdf = $this->pdf;
        $width = array_sum(array_column($columns, 1));
        $header = function () use ($pdf, $columns, $left, $width) {
            $x = $left;
            foreach ($columns as [$title, $w, $align]) {
                // Capitals when they fit the column, else the title as written, smaller if need be.
                [$words, $size] = [mb_strtoupper($title), 6.5];
                if ($pdf->width($words, $size, true) > $w - 6) {
                    $words = $title;
                    $size = $pdf->width($title, 6.5, true) > $w - 6 ? 5.5 : 6.5;
                }
                $pdf->text($this->anchor($x, $w, $align), $this->y, $words, $size, true, self::MUTED, $align);
                $x += $w;
            }
            $pdf->line($left, $this->y + 5, $left + $width, $this->y + 5, 0.5, self::HAIR);
            $this->y += 17;
        };
        $header();
        foreach ($rows as $row) {
            $lines = $pdf->wrap((string) $row[0], $columns[0][1] - 8, 8.5);
            $height = 6 + 11 * count($lines);
            if ($this->y + $height > self::BOTTOM) {
                $pdf->addPage();
                $this->y = 64;
                $header();
            }
            $x = $left;
            foreach ($columns as $i => [, $w, $align]) {
                if ($i === 0) {
                    foreach ($lines as $j => $line) {
                        $pdf->text($x + 4, $this->y + $j * 11, $line, 8.5);
                    }
                } else {
                    $pdf->text($this->anchor($x, $w, $align), $this->y, (string) ($row[$i] ?? ''), 8.5, false, self::INK, $align);
                }
                $x += $w;
            }
            $pdf->line($left, $this->y + $height - 8, $left + $width, $this->y + $height - 8, 0.4, self::FAINT);
            $this->y += $height;
        }
    }

    /** Where a cell's text is anchored: its left edge, its right edge or its centre. */
    private function anchor(float $x, float $width, string $align): float
    {
        return match ($align) {
            'right' => $x + $width - 4,
            'center' => $x + $width / 2,
            default => $x + 4,
        };
    }

    private function heading(float $y, string $title, ?string $subtitle): void
    {
        $this->pdf->text(self::LEFT, $y, $title, 13, true);
        if ($subtitle !== null) {
            $this->pdf->paragraph(self::LEFT, $y + 16, self::WIDTH, $subtitle, 8.5, false, self::MUTED);
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

    /** A dashed line, as short strokes. */
    private function dashed(float $x1, float $y1, float $x2, float $y2, string $color, float $dash, float $gap, float $width): void
    {
        $length = hypot($x2 - $x1, $y2 - $y1);
        if ($length <= 0) {
            return;
        }
        [$dx, $dy] = [($x2 - $x1) / $length, ($y2 - $y1) / $length];
        for ($at = 0.0; $at < $length; $at += $dash + $gap) {
            $end = min($length, $at + $dash);
            $this->pdf->line($x1 + $dx * $at, $y1 + $dy * $at, $x1 + $dx * $end, $y1 + $dy * $end, $width, $color);
        }
    }

    private function bandName(?HealthBand $band): string
    {
        return $band->label ?? '—';
    }

    private function pctOrDash(?float $value): string
    {
        return $value === null ? '—' : Viz::pct($value, 0);
    }

    /** Every page's footer, then the bytes. */
    private function finish(string $title): string
    {
        $pages = $this->pdf->pageCount();
        for ($i = 0; $i < $pages; $i++) {
            $this->pdf->onPage($i);
            $this->pdf->line(self::LEFT, 806, self::LEFT + self::WIDTH, 806, 0.5, self::HAIR);
            $this->pdf->text(self::LEFT, 820, $title.' · AAYMCA', 8, false, self::MUTED);
            $this->pdf->text(self::LEFT + self::WIDTH, 820, 'Page '.($i + 1).' of '.$pages, 8, false, self::MUTED, 'right');
        }

        return $this->pdf->output();
    }
}
