<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * An Excel version of the ODP, as tables to show on the page: browsers cannot display
 * a workbook themselves. Each sheet's filled rows, with the values as Excel shows them,
 * empty rows and columns left out. Read once per file (a stored file never changes).
 */
final class SpreadsheetPreview
{
    /**
     * @return list<array{title: string, rows: list<list<string>>, truncated: bool}>|null null if the file cannot be read
     */
    public static function of(Document $document, int $maxRows = 400, int $maxColumns = 20): ?array
    {
        return Cache::remember("xlsx-preview:{$document->sha256}:{$maxRows}:{$maxColumns}", now()->addDay(), function () use ($document, $maxRows, $maxColumns): ?array {
            try {
                $book = IOFactory::createReader('Xlsx')->load(Storage::disk($document->disk)->path($document->path));
            } catch (Throwable) {
                return null;
            }

            $sheets = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                try {
                    $cells = $sheet->toArray(null, true, true, false);
                } catch (Throwable) {
                    $cells = $sheet->toArray(null, false, true, false);
                }

                $rows = [];
                foreach ($cells as $row) {
                    $row = array_map(fn ($v) => trim((string) $v), $row);
                    if (implode('', $row) !== '') {
                        $rows[] = $row;
                    }
                }
                if ($rows === []) {
                    continue;
                }

                // Keep only the columns that hold something, up to the limit.
                $width = max(array_map(fn (array $r) => (int) max(array_keys(array_filter($r, fn ($v) => $v !== '')) ?: [0]) + 1, $rows));
                $width = min($width, $maxColumns);
                $truncated = count($rows) > $maxRows;
                $rows = array_map(fn (array $r) => array_pad(array_slice($r, 0, $width), $width, ''), array_slice($rows, 0, $maxRows));

                $sheets[] = ['title' => $sheet->getTitle(), 'rows' => $rows, 'truncated' => $truncated];
            }

            return $sheets;
        });
    }
}
