<?php

namespace App\Support;

use App\Oha\Report\ReportReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * What changed between two copies of an ODP: for a workbook, each cell whose value
 * changed (sheet, cell, before, after); for a Word file, the lines added and removed.
 * Used to note the changes made to a signed ODP in Google Drive, during Stage 2.
 */
final class OdpChanges
{
    private const LIMIT = 200;

    /**
     * @return array{kind: string, count: int, cells?: list<array{sheet: string, cell: string, before: string, after: string}>, added?: list<string>, removed?: list<string>, truncated: bool}
     */
    public static function between(string $beforePath, string $afterPath, string $format): array
    {
        try {
            return match ($format) {
                'xlsx' => self::cells($beforePath, $afterPath),
                'docx' => self::lines($beforePath, $afterPath),
                default => ['kind' => 'file', 'count' => 1, 'truncated' => false],
            };
        } catch (Throwable) {
            return ['kind' => 'file', 'count' => 1, 'truncated' => false];
        }
    }

    /**
     * @return array{kind: string, count: int, cells: list<array{sheet: string, cell: string, before: string, after: string}>, truncated: bool}
     */
    private static function cells(string $beforePath, string $afterPath): array
    {
        $before = self::values($beforePath);
        $after = self::values($afterPath);

        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = $before[$key] ?? '';
            $new = $after[$key] ?? '';
            if ($old !== $new) {
                [$sheet, $cell] = explode('!', $key, 2);
                $changes[] = ['sheet' => $sheet, 'cell' => $cell, 'before' => $old, 'after' => $new];
            }
        }

        return ['kind' => 'cells', 'count' => count($changes), 'cells' => array_slice($changes, 0, self::LIMIT), 'truncated' => count($changes) > self::LIMIT];
    }

    /**
     * Every cell's value as shown, by "sheet!cell". Formulas count by the result Excel saved.
     *
     * @return array<string, string>
     */
    private static function values(string $path): array
    {
        $book = IOFactory::createReader('Xlsx')->load($path);
        $values = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $value = $cell->getDataType() === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();
                $text = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : trim((string) (is_scalar($value) ? $value : ($value?->__toString() ?? '')));
                if ($text !== '') {
                    $values[$sheet->getTitle().'!'.$coordinate] = mb_substr($text, 0, 500);
                }
            }
        }
        $book->disconnectWorksheets();

        return $values;
    }

    /**
     * @return array{kind: string, count: int, added: list<string>, removed: list<string>, truncated: bool}
     */
    private static function lines(string $beforePath, string $afterPath): array
    {
        $reader = new ReportReader;
        $old = array_column($reader->read($beforePath, 'docx')->paragraphs, 'text');
        $new = array_column($reader->read($afterPath, 'docx')->paragraphs, 'text');
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        $count = count($added) + count($removed);

        return ['kind' => 'lines', 'count' => $count, 'added' => array_slice($added, 0, self::LIMIT), 'removed' => array_slice($removed, 0, self::LIMIT), 'truncated' => $count > self::LIMIT * 2];
    }
}
