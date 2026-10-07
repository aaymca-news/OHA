<?php

namespace App\Support;

use App\Models\Document;
use App\Models\FormUpload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * A workbook (an Excel ODP, or an uploaded OHA form) laid out on the page as close to
 * Excel as a web page allows: browsers cannot display a workbook themselves.
 *
 * Kept from the file: merged cells, column widths, bold and italic, text and fill
 * colours, alignment, and ticked or unticked boxes (TRUE/FALSE). Left out: hidden
 * sheets, rows and columns, columns with nothing in them, and runs of empty rows
 * (shown as one thin gap). Values are shown as Excel shows them, using the results
 * Excel saved for formulas. Read once per file: a stored file never changes.
 */
final class SpreadsheetPreview
{
    private const CACHE_VERSION = 3;

    /**
     * @return list<array{title: string, columns: list<int>, rows: list<array{gap: bool, header: bool, cells: list<array<string, mixed>>}>, truncated: bool}>|null
     *                                                                                                                                                             null if the file cannot be read
     */
    public static function of(Document|FormUpload $file, int $maxRows = 400, int $maxColumns = 30): ?array
    {
        $key = 'xlsx-preview:v'.self::CACHE_VERSION.":{$file->sha256}:{$maxRows}:{$maxColumns}";

        return Cache::remember($key, now()->addDay(), function () use ($file, $maxRows, $maxColumns): ?array {
            try {
                $book = IOFactory::createReader('Xlsx')->load(Storage::disk($file->disk)->path($file->path));
            } catch (Throwable) {
                return null;
            }

            $sheets = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                    continue;
                }
                try {
                    $laidOut = self::sheet($sheet, $maxRows, $maxColumns);
                } catch (Throwable) {
                    $laidOut = null;
                }
                if ($laidOut !== null) {
                    $sheets[] = $laidOut;
                }
            }

            return $sheets;
        });
    }

    /**
     * @return array{title: string, columns: list<int>, rows: list<array{gap: bool, header: bool, cells: list<array<string, mixed>>}>, truncated: bool}|null
     */
    private static function sheet(Worksheet $sheet, int $maxRows, int $maxColumns): ?array
    {
        // What each visible cell shows, by row and column number.
        $values = [];
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            [$col, $row] = Coordinate::indexesFromString($coordinate);
            if (! self::rowVisible($sheet, $row) || ! self::columnVisible($sheet, $col)) {
                continue;
            }
            $cell = $sheet->getCell($coordinate);
            $shown = self::shown($cell);
            if ($shown !== null) {
                $values[$row][$col] = $shown;
            }
        }
        if ($values === []) {
            return null;
        }

        // Merged ranges, by their top-left cell.
        $merges = [];
        $covered = [];
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [[$c1, $r1], [$c2, $r2]] = array_map(fn ($corner) => Coordinate::indexesFromString($corner), explode(':', $range) + [1 => $range]);
            $merges[$r1][$c1] = [$r2, $c2];
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($r !== $r1 || $c !== $c1) {
                        $covered[$r][$c] = true;
                    }
                }
            }
        }

        // The columns to show: those with something in them, or under a merge that has.
        $used = [];
        foreach ($values as $row => $cells) {
            foreach (array_keys($cells) as $col) {
                $used[$col] = true;
                if (isset($merges[$row][$col])) {
                    for ($c = $col; $c <= $merges[$row][$col][1]; $c++) {
                        $used[$c] = self::columnVisible($sheet, $c) ? true : ($used[$c] ?? false);
                    }
                }
            }
        }
        $columns = array_keys(array_filter($used));
        sort($columns);
        $truncatedWide = count($columns) > $maxColumns;
        $columns = array_slice($columns, 0, $maxColumns);
        $kept = array_flip($columns);

        $firstRow = min(array_keys($values));
        $lastRow = max(array_keys($values));
        // A merge reaching below the last value still needs its rows.
        foreach ($merges as $r1 => $byCol) {
            foreach ($byCol as $c1 => [$r2]) {
                if (isset($values[$r1][$c1])) {
                    $lastRow = max($lastRow, $r2);
                }
            }
        }

        $rows = [];
        $truncated = $truncatedWide;
        $previousGap = false;
        for ($r = $firstRow; $r <= $lastRow; $r++) {
            if (! self::rowVisible($sheet, $r)) {
                continue;
            }
            $hasContent = isset($values[$r]);
            $spanned = isset($covered[$r]) && array_intersect_key($covered[$r], $kept) !== [];
            if (! $hasContent && ! $spanned) {
                // A run of empty rows becomes one thin gap.
                if (! $previousGap) {
                    $rows[] = ['gap' => true, 'header' => false, 'cells' => []];
                    $previousGap = true;
                }

                continue;
            }
            $previousGap = false;

            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }

            $cells = [];
            $filled = 0;
            foreach ($columns as $c) {
                if (isset($covered[$r][$c])) {
                    continue;
                }
                $span = $merges[$r][$c] ?? null;
                $colspan = $span === null ? 1 : count(array_filter($columns, fn ($k) => $k >= $c && $k <= $span[1]));
                $rowspan = $span === null ? 1 : max(1, count(array_filter(range($r, $span[0]), fn ($k) => self::rowVisible($sheet, $k))));
                $cell = self::style($sheet, $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r));
                $cell['value'] = $values[$r][$c] ?? '';
                $cell['colspan'] = $colspan;
                $cell['rowspan'] = $rowspan;
                if ($cell['fill'] !== null && $cell['value'] !== '') {
                    $filled++;
                }
                $cells[] = $cell;
            }

            $rows[] = ['gap' => false, 'header' => false, 'filled' => $filled, 'cells' => $cells];
        }

        // No gap at the very end.
        while ($rows !== [] && end($rows)['gap']) {
            array_pop($rows);
        }

        // The first row with three or more filled, coloured cells is the table's heading. Elsewhere,
        // text runs on into empty cells, as in Excel.
        $header = array_key_first(array_filter($rows, fn (array $row) => ($row['filled'] ?? 0) >= 3));
        foreach ($rows as $i => $row) {
            $rows[$i]['header'] = $i === $header;
            if (! $row['gap'] && $i !== $header) {
                $rows[$i]['cells'] = self::overflow($row['cells']);
            }
            unset($rows[$i]['filled']);
        }

        return [
            'title' => $sheet->getTitle(),
            'columns' => array_map(fn (int $c) => self::width($sheet, $c, array_column($values, $c)), $columns),
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * As in Excel, text that is not set to wrap runs on into the empty cells to its
     * right, so a heading in a narrow column reads on one line instead of a tall stack.
     *
     * @param  list<array<string, mixed>>  $cells
     * @return list<array<string, mixed>>
     */
    private static function overflow(array $cells): array
    {
        $out = [];
        foreach ($cells as $cell) {
            $last = $out === [] ? null : $out[array_key_last($out)];
            $absorbable = $cell['value'] === '' && $cell['fill'] === null && $cell['rowspan'] === 1;
            if ($last !== null && $absorbable && is_string($last['value']) && $last['value'] !== ''
                && ! $last['wrap'] && $last['rowspan'] === 1 && $last['spills']) {
                $out[array_key_last($out)]['colspan'] += $cell['colspan'];

                continue;
            }
            // Excel stops the run at the first cell that holds something, or is merged.
            $cell['spills'] = $cell['colspan'] === 1;
            $out[] = $cell;
        }

        return $out;
    }

    /** What the cell shows: a tick or an empty box for TRUE/FALSE, else the text Excel shows. Null when blank. */
    private static function shown(Cell $cell): bool|string|null
    {
        $raw = $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            $raw = $cell->getOldCalculatedValue();
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $text = $cell->getDataType() === DataType::TYPE_FORMULA
                ? NumberFormat::toFormattedString($raw, $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL)
                : (string) $cell->getFormattedValue();
        } catch (Throwable) {
            $text = is_scalar($raw) ? (string) $raw : '';
        }

        // Tabs line up nothing on a page; trailing spaces show as nothing.
        $text = rtrim(str_replace("\t", ' ', $text));

        return trim($text) === '' ? null : $text;
    }

    /**
     * @return array{bold: bool, italic: bool, color: string|null, fill: string|null, align: string|null, wrap: bool}
     */
    private static function style(Worksheet $sheet, Cell $cell): array
    {
        $style = $sheet->getStyle($cell->getCoordinate());
        $font = $style->getFont();
        $fill = $style->getFill();
        $color = strtoupper((string) $font->getColor()->getRGB());
        $background = $fill->getFillType() === Fill::FILL_SOLID ? strtoupper((string) $fill->getStartColor()->getRGB()) : null;
        $align = $style->getAlignment()->getHorizontal();

        return [
            'wrap' => (bool) $style->getAlignment()->getWrapText(),
            'bold' => (bool) $font->getBold(),
            'italic' => (bool) $font->getItalic(),
            'color' => preg_match('/^[0-9A-F]{6}$/', $color) && $color !== '000000' ? $color : null,
            'fill' => $background !== null && preg_match('/^[0-9A-F]{6}$/', $background) && $background !== 'FFFFFF' ? $background : null,
            'align' => match ($align) {
                Alignment::HORIZONTAL_CENTER, Alignment::HORIZONTAL_CENTER_CONTINUOUS => 'center',
                Alignment::HORIZONTAL_RIGHT => 'right',
                default => null,
            },
        ];
    }

    /** The column's width on screen, in pixels, from its width in Excel (in characters). */
    /**
     * The column's width on screen, in pixels: its width in Excel (in characters). A column
     * left at Excel's narrow default is widened to its content (up to about 40 characters),
     * so words are not broken letter by letter; longer text wraps.
     *
     * @param  list<bool|string>  $column  what the column's cells show
     */
    private static function width(Worksheet $sheet, int $col, array $column): int
    {
        $set = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->getWidth();
        $chars = $set > 0 ? $set : $sheet->getDefaultColumnDimension()->getWidth();
        if ($chars <= 0) {
            $chars = 8.43;
        }

        if ($set <= 0) {
            $longest = 0;
            foreach ($column as $value) {
                foreach (is_string($value) ? explode("\n", $value) : [] as $line) {
                    $longest = max($longest, mb_strlen($line));
                }
            }
            $chars = max($chars, min(40, $longest * 0.95));
        }

        return (int) max(48, min(480, round($chars * 7 + 5)));
    }

    private static function rowVisible(Worksheet $sheet, int $row): bool
    {
        return $sheet->getRowDimension($row)->getVisible();
    }

    private static function columnVisible(Worksheet $sheet, int $col): bool
    {
        return $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->getVisible();
    }
}
