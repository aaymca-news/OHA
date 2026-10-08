<?php

namespace App\Oha;

use App\Models\FormUpload;
use App\Support\FileVault;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Writes the answers typed in the platform into a copy of the uploaded OHA workbook, in
 * the cells the form keeps them in, so the workbook previewed and downloaded is the
 * corrected one. The file as uploaded is never changed: it stays as the evidence.
 *
 * A percentage goes into a percentage-formatted cell as Excel stores it (25% as 0.25).
 * The form's formulas are worked out again, so its own totals agree with the answers.
 */
final class FormWorkbookWriter
{
    /**
     * Makes (or, with nothing typed, removes) the corrected copy. Returns the stored copy,
     * or null when there is none.
     *
     * @return array{disk: string, path: string, sha256: string, size_bytes: int}|null
     */
    public function write(FormUpload $upload): ?array
    {
        $supplied = $upload->supplied ?? [];
        $cells = $upload->form_meta['cells'] ?? null;
        if ($supplied === [] || $cells === null) {
            return null;
        }

        $source = tempnam(sys_get_temp_dir(), 'oha');
        file_put_contents($source, Storage::disk($upload->disk)->get($upload->path));

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setIncludeCharts(true);
            $book = $reader->load($source);

            foreach ($supplied as $ref => $entry) {
                [$kind, $key] = explode(':', $ref, 2) + [1 => ''];
                $value = $entry['value'];
                match ($kind) {
                    'q' => $this->put($book, $cells['answers'][$key] ?? null, $value),
                    'g' => array_map(fn ($code, $share) => $this->put($book, $cells['answers'][$code] ?? null, $share), array_keys((array) $value), (array) $value),
                    'c' => $this->put($book, $cells['comments'][$key] ?? null, $value),
                    's' => $this->put($book, $cells['signoff'][$key] ?? null, $value),
                    default => null,
                };
            }

            $target = tempnam(sys_get_temp_dir(), 'oha').'.xlsx';
            $this->save($book, $target);
            $book->disconnectWorksheets();

            return FileVault::store($target, 'forms/'.$upload->artefact->assessment_id.'/corrected', 'xlsx');
        } finally {
            @unlink($source);
            if (isset($target)) {
                @unlink($target);
            }
        }
    }

    /**
     * @param  array{sheet: string, cell: string}|null  $where
     */
    private function put(Spreadsheet $book, ?array $where, mixed $value): void
    {
        $sheet = $where !== null ? $book->getSheetByName($where['sheet']) : null;
        if ($sheet === null) {
            return;
        }

        $cell = $sheet->getCell($where['cell']);
        $percentCell = str_contains((string) $sheet->getStyle($where['cell'])->getNumberFormat()->getFormatCode(), '%');
        $cell->setValue((is_int($value) || is_float($value)) && $percentCell ? $value / 100 : $value);
    }

    /** Saved with the form's formulas worked out again; if a formula cannot be, Excel works it out on opening. */
    private function save(Spreadsheet $book, string $path): void
    {
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setIncludeCharts(true);
        try {
            $writer->save($path);
        } catch (Throwable) {
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        }
    }
}
