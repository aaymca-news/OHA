<?php

namespace App\Oha\Report;

use App\Models\Movement;
use App\Models\Zone;
use App\Oha\Profile;
use App\Queries\DashboardAnalytics;
use App\Support\RedFlags;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The dashboards as an Excel workbook, for those who work the figures further (decided
 * 23 Nov 2026): the same figures as the screen and the PDF, one sheet per part.
 *
 *   movement  Summary · Areas · Over the cycle · Money and people · Policies
 *   region    Movements · Areas · Bands · Red flags · Most missed
 */
final class DashboardWorkbook
{
    /**
     * A movement's dashboard.
     *
     * @param  array<string, mixed>  $d  from DashboardAnalytics::movement()
     */
    public function movement(Movement $movement, array $d): string
    {
        $book = $this->book($movement->name.' · OHA dashboard');
        $journey = $d['journey'];
        $pace = $d['pace'];

        $this->sheet($book->getActiveSheet(), 'Summary', ['Figure', 'Value'], [
            ['Movement', $movement->name],
            ['Current score (%)', $d['pct']],
            ['Band', $d['band']->label ?? null],
            ['Baseline (%)', $journey['first'] ?? null],
            ['Points since baseline', $d['followed'] ? $journey['change'] : null],
            ['Regional average (%)', $d['region']['mean']],
            ['Against the region (points)', round($d['pct'] - $d['region']['mean'], 1)],
            ['Points earned', $d['points']['earned']],
            ['Points available', $d['points']['max']],
            ['Pace: next band', $pace['band'] ?? null],
            ['Pace: points needed per follow-up', $pace['need'] ?? null],
            ['Pace: points gained per follow-up so far', $pace['gained'] ?? null],
            ['Prepared', now()->format('Y-m-d')],
        ]);

        $this->sheet($book->createSheet(), 'Areas', ['Area', 'Points', 'Out of', 'Now (%)', 'Baseline (%)', 'Regional average (%)', 'Points still open'],
            array_map(fn (array $a) => [$a['name'], $a['points'], $a['max'], $a['pct'], $a['baseline'], $a['region'], $a['lost']], $d['shape']));

        $this->sheet($book->createSheet(), 'Over the cycle', ['Score', 'Score (%)', 'Pace to the next band (%)'],
            array_map(fn (array $p, int $i) => [$p['label'], $p['pct'], $pace['points'][$i] ?? null], $d['curve'], array_keys($d['curve'])));

        $money = $d['money'];
        $people = $d['people'];
        $this->sheet($book->createSheet(), 'Money and people', ['Figure', 'Ours', 'Region (median)'], [
            ['International funding (%)', $money['international'], $money['region']['international']],
            ['Restricted funding (%)', $money['restricted'], $money['region']['restricted']],
            ['Operating margin (%)', $money['margin'], $money['region']['margin']],
            ['Cash reserve (months)', $money['reserve_months'], null],
            ['Staff', $people['staff'], $people['region']['staff']],
            ['Volunteers', $people['volunteers'], $people['region']['volunteers']],
            ['Board members', $people['board'], $people['region']['board']],
            ['Women on the Board (%)', $people['women'], $people['region']['women']],
            ['Under 30 on the Board (%)', $people['under_30'], $people['region']['under_30']],
            ...array_map(fn ($g, $k) => ['Income: '.$g['label'].' (% of income)', $this->share($money['mix'] ?? null, $k), $money['region_mix']['shares'][$k] ?? null], Profile::INCOME_GROUPS, array_keys(Profile::INCOME_GROUPS)),
        ]);

        $this->sheet($book->createSheet(), 'Policies', ['Policy', 'Ours', 'Region (% with it)'],
            array_map(fn (array $p) => [$p['label'], $p['ours'] === true ? 'In place' : ($p['ours'] === false ? 'Not yet' : 'Not answered'), $p['region']], $d['policies']));

        return $this->bytes($book);
    }

    /**
     * AAYMCA's dashboard: the region, or one zone.
     *
     * @param  array<string, mixed>  $d  from DashboardAnalytics::region()
     */
    public function region(?Zone $zone, array $d): string
    {
        $book = $this->book('AAYMCA · OHA dashboard · '.($zone->name ?? 'The region'));
        $areas = $d['shape']['areas'];
        // Each movement's area scores now, by movement id.
        $cells = array_column(array_map(fn (array $r) => ['id' => $r['movement']->id, 'cells' => $r['cells']], $d['heatmap']), 'cells', 'id');

        $this->sheet($book->getActiveSheet(), 'Movements',
            ['Movement', 'Zone', 'Band', 'Current score (%)', 'Baseline (%)', 'Points since baseline', 'Who needs support', ...array_map(fn ($a) => $a['short'].' (%)', $areas)],
            array_map(fn (array $p) => [
                $p['movement']->name, $p['movement']->zone->name, $p['band']->label ?? null, $p['pct'], $p['baseline'], $p['change'],
                DashboardAnalytics::SUPPORT_GROUPS[$p['group']], ...array_map(fn ($a) => $cells[$p['movement']->id][$a['code']] ?? null, $areas),
            ], $d['matrix']['points']));

        $zones = count($d['shape']['zones']) > 1 ? $d['shape']['zones'] : [];
        $this->sheet($book->createSheet(), 'Areas', ['Area', 'Median at baseline (%)', 'Median now (%)', ...array_map(fn ($z) => $z['name'].' (%)', $zones)],
            array_map(fn (array $a, int $k) => [$a['name'], $d['shape']['baseline'][$k], $d['shape']['now'][$k], ...array_map(fn ($z) => $z['values'][$k], $zones)], $areas, array_keys($areas)));

        $this->sheet($book->createSheet(), 'Bands', ['Band', 'At baseline', 'Now'],
            array_map(fn (array $b) => [$b['band']->label, $b['baseline'], $b['now']], $d['bands']));

        $this->sheet($book->createSheet(), 'Red flags', ['Movement', ...array_column(RedFlags::CHECKS, 'full'), 'Flags'],
            array_map(fn (array $r) => [$r['movement']->name, ...array_map(fn ($c) => $c === null ? 'Cannot tell yet' : ($c ? 'Yes' : 'No'), array_values($r['checks'])), $r['count']], $d['flags']));

        $this->sheet($book->createSheet(), 'Most missed', ['Point', 'Area', 'Missed by', 'Of'],
            array_map(fn (array $m) => [$m['name'], $m['category'], $m['missed'], $m['of']], $d['missed']));

        return $this->bytes($book);
    }

    private function book(string $title): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($title)->setCreator('AAYMCA OHA platform');

        return $book;
    }

    /**
     * @param  array<string, float>|null  $mix
     */
    private function share(?array $mix, string $key): ?float
    {
        $sum = array_sum($mix ?? []);

        return $mix === null || $sum <= 0 ? null : round(($mix[$key] ?? 0) * 100 / $sum, 1);
    }

    /**
     * @param  list<string>  $head
     * @param  list<list<mixed>>  $rows
     */
    private function sheet(Worksheet $sheet, string $title, array $head, array $rows): void
    {
        $sheet->setTitle($title);
        // Text that a spreadsheet would read as a formula is kept as text.
        $safe = fn (mixed $v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;
        $sheet->fromArray([$head, ...array_map(fn (array $row) => array_map($safe, $row), $rows)], null, 'A1', true);
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }

    private function bytes(Spreadsheet $book): string
    {
        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'dashboard');
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
