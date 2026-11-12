<?php

namespace App\Oha\Report;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Oha\EvidenceQuestions;
use App\Queries\Journeys;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The cycle's journey as an Excel workbook, for those who work the figures further
 * (decided 12 Nov 2026). Four sheets, the same figures as the PDF:
 *
 *   Baselines       one row per baseline: when, by whom, points, %, band, ODP tasks, evidence
 *   Categories      each category's % at each baseline
 *   Changes         every answer changed at each follow-up: from, to, why, who, when, how
 *   Evidence gaps   the "Yes" answers with no evidence counted, at each baseline
 */
final class JourneyWorkbook
{
    public function __construct(private readonly Journeys $journeys) {}

    /** The workbook's bytes. */
    public function build(Assessment $assessment): string
    {
        $steps = $this->journeys->of($assessment);
        if ($steps === []) {
            throw new WorkflowRuleBroken('There is no journey yet: Baseline 1 is recorded when the assessment is closed.');
        }
        $wording = $this->journeys->wording($assessment);
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($assessment->movement->name.' · '.$assessment->period_label.' cycle journey')->setCreator('AAYMCA OHA platform');

        $this->sheet($book->getActiveSheet(), 'Baselines',
            ['Baseline', 'After follow-up', 'Recorded', 'Recorded by', 'Points', 'Out of', 'Score (%)', 'Band', 'ODP tasks done', 'ODP tasks', 'Evidence counted', 'Answers changed', '"Yes" with no evidence'],
            array_map(fn (array $s) => [
                $s['number'], $s['baseline']->follow_up_number, $s['baseline']->set_at->format('Y-m-d'), $s['baseline']->setter?->name,
                $s['points'], $s['max'], $s['pct'], $s['band']?->label, $s['tasks_done'], $s['tasks_total'], $s['evidence'],
                $s['number'] === 1 ? null : $s['changed'], $s['baseline']->evidence_gaps !== null ? count($s['baseline']->evidence_gaps) : null,
            ], $steps));

        $categories = [];
        foreach ($steps[0]['rows'] as $code => $row) {
            $categories[] = [$row['name'], ...array_map(fn (array $s) => $s['rows'][$code]['pct'] ?? null, $steps)];
        }
        $categories[] = ['Total', ...array_map(fn (array $s) => $s['pct'], $steps)];
        $this->sheet($book->createSheet(), 'Categories', ['Category (%)', ...array_map(fn (array $s) => $s['label'], $steps)], $categories);

        $changes = [];
        foreach (array_slice($steps, 1) as $s) {
            foreach ($s['baseline']->changes ?? [] as $code => $change) {
                $changes[] = [
                    $s['number'], str_replace('#2', ' (2nd)', $code), $wording[$code] ?? null,
                    self::cell($change['from']), self::cell($change['to']), $change['reason'], $change['by_name'],
                    Carbon::parse($change['at'])->format('Y-m-d'),
                    $change['via'] === 'form' ? 'Re-uploaded form'.(isset($change['file']) ? ' ('.$change['file'].')' : '') : 'On the site',
                ];
            }
        }
        $this->sheet($book->createSheet(), 'Changes', ['Baseline', 'Question', 'Wording', 'Was', 'Now', 'Why', 'By', 'On', 'How'], $changes);

        $gaps = [];
        foreach ($steps as $s) {
            foreach ($s['baseline']->evidence_gaps ?? [] as $code) {
                $gaps[] = [$s['number'], EvidenceQuestions::shown($code), EvidenceQuestions::label($code), EvidenceQuestions::needed($code)];
            }
        }
        $this->sheet($book->createSheet(), 'Evidence gaps', ['Baseline', 'Question', 'What it is', 'What proves it'], $gaps);

        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'journey');
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
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

    private static function cell(mixed $value): mixed
    {
        return $value === null || $value === '' ? '(blank)' : $value;
    }
}
