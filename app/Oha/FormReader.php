<?php

namespace App\Oha;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads an OHA workbook into plain values. Every question is found by its CODE,
 * not its row, so a movement inserting a row does not break the read.
 *
 * Nothing is recalculated here: answers are the typed values, and the totals the
 * form printed are Excel's cached results, kept only for the cross-check.
 */
final class FormReader
{
    /** Printed-total labels on "1 General Information", by first word. */
    private const TOTAL_LABELS = [
        'financial' => 'financial', 'governance' => 'governance', 'constitution' => 'constitution',
        'monitoring' => 'me', 'strategic' => 'strategy', 'diversity' => 'diversity',
        'communications' => 'comms', 'property' => 'property', 'staff' => 'staff',
    ];

    /**
     * @throws UnreadableForm
     */
    public function read(string $path): ReadForm
    {
        try {
            // Formats are read too (not data-only): a percentage-formatted cell holds 0.73 for 73%.
            $reader = IOFactory::createReader('Xlsx');
            $workbook = $reader->load($path);
        } catch (Throwable $e) {
            throw new UnreadableForm('That does not look like a readable Excel workbook.', previous: $e);
        }

        try {
            // Answers written in words are read as the number or option they mean.
            return Interpreter::apply($this->readWorkbook($workbook));
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function readWorkbook(Spreadsheet $workbook): ReadForm
    {
        $sheetNames = $workbook->getSheetNames();
        $generalName = $this->sheetNamed($sheetNames, FormDefinition::GENERAL_SHEET);

        $categorySheets = [];
        foreach (FormDefinition::categories() as $code => $category) {
            $categorySheets[$code] = $this->sheetNamed($sheetNames, $category['sheet']);
        }

        $answers = $notes = $locations = $comments = $overwritten = [];
        $scoring = [];
        foreach (FormDefinition::categories() as $category) {
            foreach ($category['questions'] as $q) {
                if ($q->points !== null) {
                    $scoring[$q->code] = true;
                }
            }
        }

        $general = $generalName !== null ? $workbook->getSheetByName($generalName) : null;
        if ($general !== null) {
            foreach ($this->questionRows($general, FormDefinition::GENERAL_COLUMNS) as $row) {
                $answers[$row['code']] = $row['answer'];
                $notes[$row['code']] = $row['info'];
                $locations[$row['code']] = ['sheet' => $generalName, 'row' => $row['row'], 'text' => $row['text'], 'category' => 'general'];
            }
        }

        foreach ($categorySheets as $code => $name) {
            $sheet = $name !== null ? $workbook->getSheetByName($name) : null;
            if ($sheet === null) {
                continue;
            }
            foreach ($this->questionRows($sheet, FormDefinition::CATEGORY_COLUMNS) as $row) {
                $answers[$row['code']] = $row['answer'];
                $notes[$row['code']] = $row['info'];
                $locations[$row['code']] = ['sheet' => $name, 'row' => $row['row'], 'text' => $row['text'], 'category' => $code];

                // A scoring cell holding typed text has lost its formula: the form's own total is then wrong.
                $score = $sheet->getCell('E'.$row['row'])->getValue();
                if (isset($scoring[$row['code']]) && ! (is_string($score) && str_starts_with($score, '='))) {
                    $overwritten[$row['code']] = 'E'.$row['row'];
                }
            }
            $comments[$code] = $this->improvementComment($sheet);
        }

        $welcome = $workbook->getSheet(0);

        return new ReadForm(
            sheetNames: $sheetNames,
            generalSheet: $generalName,
            categorySheets: $categorySheets,
            version: $general !== null ? $this->text($general->getCell('A2')) : null,
            answers: $answers,
            notes: $notes,
            locations: $locations,
            comments: $comments,
            printedTotals: $general !== null ? $this->printedTotals($general) : [],
            signoff: $general !== null ? $this->signoff($general) : [],
            consent: $welcome->getCell('B72')->getValue() === true,
            overwrittenScoring: $overwritten,
        );
    }

    /**
     * Sheets are matched on their leading number, since names are truncated
     * ("4 Constitution, Bylaws, Polici") and may be edited.
     *
     * @param  list<string>  $names
     */
    private function sheetNamed(array $names, int $number): ?string
    {
        foreach ($names as $name) {
            if (preg_match('/^\s*'.$number.'\s/', $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Every Q-coded row on a sheet. A code used twice gets "#2" on its second use.
     *
     * @param  array{code: string, text: string, answer: string, info: string}  $columns
     * @return list<array{code: string, row: int, text: string, answer: mixed, info: mixed}>
     */
    private function questionRows(Worksheet $sheet, array $columns): array
    {
        $rows = [];
        $seen = [];

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->raw($sheet->getCell($columns['code'].$r));
            if (! is_string($code) || ! preg_match('/^Q\d{3,4}$/i', trim($code))) {
                continue;
            }

            $key = strtoupper(trim($code));
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > 1) {
                $key .= '#'.$seen[$key];
            }

            $rows[] = [
                'code' => $key,
                'row' => $r,
                'text' => $this->clip((string) $this->raw($sheet->getCell($columns['text'].$r)), 140),
                'answer' => $this->percentAware($sheet, $columns['answer'].$r),
                'info' => $this->clean($this->raw($sheet->getCell($columns['info'].$r))),
            ];
        }

        return $rows;
    }

    /**
     * The answer to "Based on this assessment, which areas of improvement…".
     *
     * @return array{row: int|null, text: string|null}
     */
    private function improvementComment(Worksheet $sheet): array
    {
        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            if (preg_match('/^based on this assessment/i', trim((string) $this->raw($sheet->getCell('A'.$r))))) {
                $value = $this->clean($this->raw($sheet->getCell('D'.$r)));
                $text = $value !== null && ! preg_match('/^optional$/i', (string) $value) ? trim((string) $value) : null;

                return ['row' => $r, 'text' => $text];
            }
        }

        return ['row' => null, 'text' => null];
    }

    /**
     * @return array<string, float>
     */
    private function printedTotals(Worksheet $general): array
    {
        $totals = [];

        for ($r = 25; $r <= 40; $r++) {
            $label = Answer::lower($this->raw($general->getCell('B'.$r)));
            $firstWord = preg_split('/[ \/&]+/', $label)[0] ?? '';
            $code = self::TOTAL_LABELS[$firstWord] ?? null;
            $printed = Answer::number($this->raw($general->getCell('D'.$r)));

            if ($code !== null && $printed !== null && ! isset($totals[$code])) {
                $totals[$code] = $printed;
            }
        }

        return $totals;
    }

    /**
     * @return array<string, array{row: int, answer: mixed, mustBeYes: bool}>
     */
    private function signoff(Worksheet $general): array
    {
        $found = [];

        for ($r = 35; $r <= 60; $r++) {
            $label = trim((string) $this->raw($general->getCell('B'.$r)));
            if ($label === '') {
                continue;
            }
            foreach (FormDefinition::SIGNOFF as $line) {
                if (! isset($found[$line['label']]) && preg_match($line['match'], $label)) {
                    $found[$line['label']] = [
                        'row' => $r,
                        'answer' => $this->clean($this->raw($general->getCell('D'.$r))),
                        'mustBeYes' => $line['mustBeYes'],
                    ];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The value a person sees in the cell: a formula cell gives Excel's cached
     * result, a whole number comes back as an integer.
     */
    private function raw(Cell $cell): mixed
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            $value = $cell->getOldCalculatedValue();
        }

        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * An answer as the person typed it. A cell formatted as a percentage stores 73% as
     * 0.73, while the form asks for whole numbers ("25 for 25%"), so it is read back as 73.
     */
    private function percentAware(Worksheet $sheet, string $coordinate): mixed
    {
        $value = $this->clean($this->raw($sheet->getCell($coordinate)));

        if ((is_int($value) || is_float($value)) && str_contains($sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode(), '%')) {
            $value = round($value * 100, 4);

            return floor($value) === $value ? (int) $value : $value;
        }

        return $value;
    }

    private function text(Cell $cell): ?string
    {
        $value = $this->raw($cell);

        return $value === null ? null : trim((string) $value);
    }

    /** Blank strings and the form's own prompts read as no answer. */
    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (FormDefinition::PLACEHOLDERS as $placeholder) {
            if (preg_match($placeholder, $value)) {
                return null;
            }
        }

        return $value;
    }

    private function clip(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
