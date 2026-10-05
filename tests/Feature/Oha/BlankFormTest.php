<?php

use App\Models\Category;
use App\Models\Movement;
use App\Oha\FindingRule;
use App\Oha\FormChecker;
use App\Oha\FormDefinition;
use App\Oha\FormReader;
use Database\Seeders\MovementsSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->blank = (string) config('oha.blank_form');
});

it('offers the official form with all twelve sheets and every question code', function () {
    $workbook = IOFactory::load($this->blank);
    $codes = 0;
    foreach ($workbook->getWorksheetIterator() as $sheet) {
        foreach ($sheet->toArray(null, false, false) as $row) {
            $codes += count(array_filter($row, fn ($v) => is_string($v) && preg_match('/^Q\d{3,4}$/', trim($v))));
        }
    }

    expect($workbook->getSheetNames())->toHaveCount(12)
        ->and($workbook->getSheetNames()[1])->toBe('1 General Information')
        ->and($codes)->toBeGreaterThanOrEqual(181);
});

it('carries no Zambia data', function () {
    $workbook = IOFactory::load($this->blank);
    $text = '';
    foreach ($workbook->getWorksheetIterator() as $sheet) {
        $text .= json_encode($sheet->toArray(null, false, false));
    }

    expect($text)->not->toMatch('/zambia|chilumbiu|kwacha|chirwa|teveta|encroach/i');
});

it('refuses the blank form uploaded unfilled', function () {
    $this->seed(MovementsSeeder::class);
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();

    $result = app(FormChecker::class)->check(app(FormReader::class)->read($this->blank), $zambia);

    expect($result->ok)->toBeFalse()
        ->and(collect($result->findings)->pluck('rule'))->toContain(FindingRule::EmptyForm);
});

it('lets every category reach exactly its maximum, as the OW2026 template does', function () {
    $attainable = FormDefinition::attainablePoints();

    // Checked against the official template: each category's formulas inside its official
    // total add up to the weight on the Scores sheet (Q424 counts; Q710 lies outside the total).
    foreach (Category::query()->weighted()->get() as $category) {
        expect($attainable[$category->code])->toBe($category->max_points, $category->code);
    }

    expect(array_sum(array_intersect_key($attainable, Category::query()->weighted()->pluck('max_points', 'code')->all())))->toBe(84)
        ->and($attainable['property'])->toBe(0);
});
