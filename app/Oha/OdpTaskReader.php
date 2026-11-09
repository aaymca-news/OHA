<?php

namespace App\Oha;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the tasks of an ODP workbook laid out like the AAYMCA ODP template: a header
 * row with TASKS and TO COMPLETE THE TASK BY DEADLINE, "STRATEGIC PRIORITY …" rows
 * above each group, and one task per row (an objective's further tasks leave its
 * number blank). The header may be repeated under each priority, and the columns
 * may move (a template without RESOURCES NEEDED); they are found by their headings.
 *
 * Deadlines are written in many ways: a date cell, "Dec-25", "By May 2026",
 * "1. Apr-May 2026 2. Board approval by June 2026". The latest date mentioned is
 * the deadline (a month alone means its last day). One with no year ("End of June",
 * "End of first quarter") takes the year from the ODP's period of implementation and
 * is marked as guessed, to be checked. What has no date ("Ongoing", "Annually")
 * keeps its words and no date, for a person to set.
 */
final class OdpTaskReader
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** A month's name or its short form (and "Aprl", seen in a real ODP), as a whole word. */
    private const MONTH = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il|l)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b';

    /**
     * @param  int|null  $year  the year for a deadline without one, when the ODP gives no period
     * @return array{tasks: list<array{ref: string|null, priority: string|null, task: string, deadline_text: string|null, due_on: string|null, date_guessed: bool, responsible: string|null, done: bool}>, problem: string|null}
     */
    public static function read(string $path, ?int $year = null): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (Throwable) {
            return ['tasks' => [], 'problem' => 'The ODP file could not be opened as a workbook.'];
        }

        foreach ($book->getAllSheets() as $sheet) {
            $tasks = self::fromSheet($sheet, $year);
            if ($tasks !== null) {
                return ['tasks' => $tasks, 'problem' => $tasks === [] ? 'The ODP has the task table, but no task is filled in yet.' : null];
            }
        }

        return ['tasks' => [], 'problem' => 'No task table was found in the ODP (the row with TASKS and TO COMPLETE THE TASK BY DEADLINE).'];
    }

    /**
     * The latest date a deadline mentions, as Y-m-d (null when it mentions none), and
     * whether its year was guessed: taken from $year (the ODP's period), as the text
     * gave none. $fromMonth is the month the period starts in: a month before it falls
     * in the next year ("End of January" in a plan from April 2026 to March 2027).
     *
     * @return array{0: string|null, 1: bool}
     */
    public static function deadline(string $text, ?int $year = null, ?int $fromMonth = null): array
    {
        $text = mb_strtolower($text);
        $dates = [];

        // A month, then perhaps a day, then a year: "May 2026", "Dec-25", "April 13/2026", "Jan 4, 2026".
        preg_match_all('/\b'.self::MONTH."\.?[\s,\-\/]*(?:(\d{1,2})(?:st|nd|rd|th)?[\s,\-\/]+)?'?(\d{4}|\d{2})\b/u", $text, $m, PREG_SET_ORDER);
        foreach ($m as [, $month, $day, $written]) {
            $dates[] = self::date((int) $written, self::month($month), $day !== '' ? (int) $day : null);
        }
        // A day before the month: "13 April 2026", "30th June 2026".
        preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-]+'.self::MONTH.'\.?[\s,\-]+(\d{4})\b/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as [, $day, $month, $written]) {
            $dates[] = self::date((int) $written, self::month($month), (int) $day);
        }
        // Day first, in figures: 13/04/2026, 13-04-2026.
        preg_match_all('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $text, $m, PREG_SET_ORDER);
        foreach ($m as [, $day, $month, $written]) {
            $dates[] = self::date((int) $written, (int) $month, (int) $day);
        }

        // Parts of a year, perhaps with the year: "Q3 2026", "End of first quarter 2026"
        // ("quater", as some ODPs spell it), "Mid-year", "End of the year". [last month, year or ''].
        $parts = [];
        $year4 = '(?:\s*,?\s*(?:of\s+)?(20[2-4]\d))?';
        preg_match_all('/\bq([1-4])\b'.$year4.'/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $parts[] = [(int) $hit[1] * 3, $hit[2] ?? ''];
        }
        $quarters = ['first' => 3, '1st' => 3, 'second' => 6, '2nd' => 6, 'third' => 9, '3rd' => 9, 'fourth' => 12, '4th' => 12, 'last' => 12];
        preg_match_all('/\b(first|1st|second|2nd|third|3rd|fourth|4th|last)\s+quar?t[ae]r\b'.$year4.'/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $parts[] = [$quarters[$hit[1]], $hit[2] ?? ''];
        }
        preg_match_all('/\bmid[\s\-]?year\b'.$year4.'/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $parts[] = [6, $hit[1] ?? ''];
        }
        preg_match_all('/\bend of (?:the )?year\b'.$year4.'/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $parts[] = [12, $hit[1] ?? ''];
        }
        foreach ($parts as [$month, $written]) {
            if ($written !== '') {
                $dates[] = self::date((int) $written, $month, null);
            }
        }

        $dates = array_filter($dates);
        if ($dates !== []) {
            return [max($dates), false];
        }

        // A year alone: "End of 2026", "2027".
        if (preg_match_all('/\b(20[2-4]\d)\b/', $text, $m) > 0) {
            return [self::date(max(array_map('intval', $m[1])), 12, null), false];
        }
        if ($year === null) {
            return [null, false];
        }

        // No year: a month or a part of the year, in the period's year.
        $guessed = [];
        $in = fn (int $month) => $fromMonth !== null && $month < $fromMonth ? $year + 1 : $year;
        preg_match_all('/\b'.self::MONTH.'/u', $text, $m);
        foreach ($m[1] as $month) {
            $guessed[] = self::date($in(self::month($month)), self::month($month), null);
        }
        foreach ($parts as [$month]) {
            $guessed[] = self::date($in($month), $month, null);
        }
        $guessed = array_filter($guessed);

        return $guessed === [] ? [null, false] : [max($guessed), true];
    }

    /**
     * @return list<array{ref: string|null, priority: string|null, task: string, deadline_text: string|null, due_on: string|null, date_guessed: bool, responsible: string|null, done: bool}>|null null when the sheet has no task table
     */
    private static function fromSheet(Worksheet $sheet, ?int $year): ?array
    {
        $columns = null;
        $tasks = [];
        $ref = null;
        $priority = null;
        $fromMonth = null;
        $last = min($sheet->getHighestDataRow(), 600);

        for ($row = 1; $row <= $last; $row++) {
            $cells = self::row($sheet, $row);
            if ($cells === []) {
                continue;
            }
            $joined = mb_strtoupper(implode(' ', array_column($cells, 'text')));

            // "PERIOD OF IMPLEMENTATION: April 2026 - February 2027": the year (and month) it starts.
            if ($columns === null && str_contains($joined, 'PERIOD OF IMPLEMENTATION')) {
                $period = mb_strtolower((string) preg_replace('/^.*PERIOD OF IMPLEMENTATION:?/iu', '', implode(' ', array_column($cells, 'text'))));
                if (preg_match('/\b(20[2-4]\d)\b/', $period, $y) === 1) {
                    $year = (int) $y[1];
                    if (preg_match('/\b'.self::MONTH.'[\s,\-]*'.$y[1].'/u', $period, $mo) === 1) {
                        $fromMonth = self::month($mo[1]);
                    }
                }

                continue;
            }

            if (str_contains($joined, 'TASKS') && str_contains($joined, 'DEADLINE')) {
                $columns = self::columns($cells);

                continue;
            }
            if ($columns === null) {
                continue;
            }
            if (str_contains($joined, 'PLAN CREATED BY') || str_contains($joined, 'PLAN APPROVED BY')) {
                break;
            }
            if (str_contains($joined, 'STRATEGIC PRIORITY')) {
                $priority = self::priority($cells);
                $ref = null;

                continue;
            }

            $number = $cells[$columns['number']]['text'] ?? null;
            if ($number !== null && preg_match('/^\d+(\.\d+)*$/', $number) === 1) {
                $ref = $number;
            }
            $task = $cells[$columns['task']]['text'] ?? null;
            if ($task === null || preg_match('/^[-–—.\s]*$/u', $task) === 1) {
                continue;
            }

            $deadline = $cells[$columns['deadline']] ?? null;
            [$due, $guessed] = match (true) {
                $deadline === null => [null, false],
                $deadline['date'] !== null => [$deadline['date'], false],
                default => self::deadline($deadline['text'], $year, $fromMonth),
            };
            $responsible = $columns['responsible'] !== null ? ($cells[$columns['responsible']]['text'] ?? null) : null;

            $tasks[] = [
                'ref' => $ref,
                'priority' => $priority,
                'task' => mb_substr($task, 0, 4000),
                'deadline_text' => $deadline !== null ? mb_substr($deadline['text'], 0, 500) : null,
                'due_on' => $due,
                'date_guessed' => $guessed,
                'responsible' => $responsible !== null ? mb_substr($responsible, 0, 500) : null,
                'done' => $columns['done'] !== null && self::done($cells[$columns['done']] ?? null),
            ];
        }

        return $columns === null ? null : $tasks;
    }

    /**
     * The non-empty cells of a row, by column letter, copied out at once: the workbook
     * reuses its cell objects as it moves on.
     *
     * @return array<string, array{text: string, value: mixed, date: string|null}>
     */
    private static function row(Worksheet $sheet, int $row): array
    {
        $cells = [];
        foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
            $value = $cell->getValue();
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $cell->getFormattedValue()));
            if ($value === null || $text === '') {
                continue;
            }

            $date = null;
            if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                $at = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
                // A date shown as month and year ("Dec-25") means the end of that month.
                $monthOnly = ! str_contains(mb_strtolower((string) $cell->getStyle()->getNumberFormat()->getFormatCode()), 'd');
                $date = ($monthOnly ? $at->endOfMonth() : $at)->toDateString();
            }

            $cells[$cell->getColumn()] = ['text' => $text, 'value' => $value, 'date' => $date];
        }

        return $cells;
    }

    /**
     * @param  array<string, array{text: string, value: mixed, date: string|null}>  $cells
     * @return array{number: string, task: string, deadline: string, responsible: string|null, done: string|null}
     */
    private static function columns(array $cells): array
    {
        $find = function (string $needle) use ($cells): ?string {
            foreach ($cells as $column => $cell) {
                if (str_contains(mb_strtoupper($cell['text']), $needle)) {
                    return (string) $column;
                }
            }

            return null;
        };

        return [
            'number' => $find('№') ?? (string) array_key_first($cells),
            'task' => (string) $find('TASKS'),
            'deadline' => (string) $find('DEADLINE'),
            'responsible' => $find('RESPONSIBLE'),
            'done' => $find('COMPLETED'),
        ];
    }

    /**
     * The name after "STRATEGIC PRIORITY ONE:", such as FINANCIAL STABILITY.
     *
     * @param  array<string, array{text: string, value: mixed, date: string|null}>  $cells
     */
    private static function priority(array $cells): ?string
    {
        $found = false;
        foreach ($cells as $cell) {
            if (str_contains(mb_strtoupper($cell['text']), 'STRATEGIC PRIORITY')) {
                $found = true;
                $rest = trim((string) preg_replace('/^.*STRATEGIC PRIORITY[^:]*:?/iu', '', $cell['text']));
                if ($rest !== '') {
                    return mb_substr($rest, 0, 255);
                }

                continue;
            }
            if ($found) {
                return mb_substr($cell['text'], 0, 255);
            }
        }

        return null;
    }

    /**
     * @param  array{text: string, value: mixed, date: string|null}|null  $cell
     */
    private static function done(?array $cell): bool
    {
        if ($cell === null) {
            return false;
        }
        if (is_bool($cell['value'])) {
            return $cell['value'];
        }

        return in_array(mb_strtolower($cell['text']), ['true', 'yes', 'done', 'completed', 'task completed', 'complete', '✓', 'x'], true);
    }

    private static function month(string $name): int
    {
        return self::MONTHS[substr($name, 0, 3)];
    }

    private static function date(int $year, int $month, ?int $day): ?string
    {
        if ($year < 100) {
            $year += 2000;
        }
        if ($year < 2020 || $year > 2045 || $month < 1 || $month > 12) {
            return null;
        }
        if ($day === null) {
            return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        return checkdate($month, $day, $year) ? Carbon::create($year, $month, $day)->toDateString() : null;
    }
}
