<?php

use App\Models\Category;
use App\Oha\FindingRule;
use App\Oha\Report\ReportChecker;
use App\Oha\Report\ReportReader;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Journey;
use Tests\Support\WordDocument;

/*
 * The report and ODP templates staff download: the official templates of 29 Oct 2026,
 * corrected to agree with the OHA form.
 */

/** The report template's score table: category => its "Max" cell. */
function reportTemplateMaxima(): array
{
    $zip = new ZipArchive;
    $zip->open((string) config('oha.report_template'));
    preg_match('#<w:tbl>.*?</w:tbl>#s', (string) $zip->getFromName('word/document.xml'), $table);
    preg_match_all('#<w:tr[ >].*?</w:tr>#s', $table[0], $rows);

    $maxima = [];
    foreach (array_slice($rows[0], 1) as $row) {
        preg_match_all('#<w:tc>.*?</w:tc>#s', $row, $cells);
        $text = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $cells[0]);
        $maxima[$text[0]] = $text[2];
    }

    return $maxima;
}

it('gives every scored category in the report template the maximum the form gives it', function () {
    $maxima = reportTemplateMaxima();

    foreach (Category::query()->weighted()->get() as $category) {
        $row = collect($maxima)->keys()->first(fn ($title) => ReportChecker::categoryNamedBy($title) === $category->code);
        expect($row)->not->toBeNull($category->code)
            ->and((int) $maxima[$row])->toBe($category->max_points, $category->code);
    }

    $scored = collect($maxima)->filter(fn ($max, $title) => ReportChecker::categoryNamedBy($title) !== null && is_numeric($max));
    expect($scored->sum(fn ($max) => (int) $max))->toBe(84);
});

it('reads the report template as a complete report, with all nine categories', function () {
    Notification::fake();
    $assessment = Journey::begin()->assessmentAt('form_approved');

    $result = app(ReportChecker::class)->check(app(ReportReader::class)->read((string) config('oha.report_template'), 'docx'), $assessment);
    $refs = collect($result['findings'])->pluck('ref');

    expect($refs)->not->toContain('r:overview')
        ->and($refs)->not->toContain('r:strengths')
        ->and($refs)->not->toContain('r:risks')
        ->and(collect($result['findings'])->where('rule', FindingRule::ReportCategoryMissing)->all())->toBe([]);
});

it('does not let the Programmes and Projects section stand in for Communications & Branding', function () {
    Notification::fake();
    $journey = Journey::begin();
    $assessment = $journey->assessmentAt('form_approved');
    // Communications & Branding keeps its title but loses its text to a Programmes section.
    $lines = WordDocument::zambiaReport();
    $at = array_search('### 7. Communications and Branding', $lines, true);
    array_splice($lines, $at + 1, 0, ['### Programmes and Projects']);

    $result = app(ReportChecker::class)->check(app(ReportReader::class)->read(WordDocument::make($lines), 'docx'), $assessment);

    expect(collect($result['findings'])->pluck('ref'))->toContain('r:growth:comms');
});

it('numbers the ODP template’s nine priorities 1.1 to 9.3', function () {
    $sheet = IOFactory::load((string) config('oha.odp_template'))->getActiveSheet();
    $titles = $numbers = [];
    foreach ($sheet->toArray(null, false, false, true) as $row) {
        $b = trim((string) $row['B']);
        if (str_starts_with($b, 'STRATEGIC PRIORITY')) {
            $titles[] = $b;
        } elseif (preg_match('/^\d+(\.\d+)?$/', $b)) {
            $numbers[] = number_format((float) $b, 1);
        }
    }

    $expected = [];
    foreach (range(1, 9) as $p) {
        foreach (range(1, 3) as $k) {
            $expected[] = $p.'.'.$k;
        }
    }

    expect($titles)->toHaveCount(9)
        ->and(array_unique($titles))->toHaveCount(9)
        ->and($titles[8])->toBe('STRATEGIC PRIORITY NINE:')
        ->and($numbers)->toBe($expected);
});
