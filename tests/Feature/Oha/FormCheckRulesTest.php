<?php

use App\Enums\FindingSeverity;
use App\Models\Movement;
use App\Oha\CheckResult;
use App\Oha\FindingRule;
use App\Oha\FormChecker;
use App\Oha\FormReader;
use Database\Seeders\MovementsSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * The Zambia form with answers changed, to show each rule. Changing the read
 * answers is exactly what editing the cells would do, because the checker
 * judges answers, never the workbook's cached totals.
 */

beforeEach(function () {
    $this->seed(MovementsSeeder::class);
    $this->read = app(FormReader::class)->read(zambiaFormPath());
    $this->check = fn (array $changes, string $movement = 'zambia'): CheckResult => app(FormChecker::class)->check(
        $this->read->withAnswers($changes),
        Movement::query()->where('slug', $movement)->firstOrFail(),
    );
});

function rules(CheckResult $result): array
{
    return collect($result->findings)->map(fn ($f) => $f->rule)->all();
}

it('lets a form with gaps go forward, and says why each follow-up is needed', function () {
    $result = ($this->check)(['Q239' => 'Yes', 'Q310' => null, 'Q516' => null]);

    $q240 = collect($result->findings)->firstWhere('ref', 'q:Q240');
    $q516 = collect($result->findings)->firstWhere('ref', 'q:Q516');

    expect($result->ok)->toBeTrue()
        ->and($result->gapRefs())->toContain('q:Q240', 'q:Q241', 'q:Q310', 'q:Q516')
        ->and($q240->hint)->toContain('Q239')
        ->and($q516->pointsAtStake)->toBe(1)
        ->and($result->totalPointsAtStake())->toBeGreaterThanOrEqual(1)
        // Q239 = Yes earns its point, by the form's formula.
        ->and($result->points['financial'])->toBe(13);
});

it('flags answers that are present but wrong, without blocking', function () {
    $result = ($this->check)(['Q201' => 'Maybe', 'Q209' => 'two million', 'Q216' => 90]);

    expect($result->ok)->toBeTrue()
        ->and(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::InvalidOption && $f->questionCode === 'Q201'))->toBeTrue()
        ->and(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::NonNumeric && $f->questionCode === 'Q209'))->toBeTrue()
        ->and(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::SumMismatch && str_contains($f->message, 'Income')))->toBeTrue()
        // Q201 no longer scores, so the total the form printed now disagrees.
        ->and(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::TotalMismatch && $f->categoryCode === 'financial'))->toBeTrue();
});

it('refuses a form for another country, and gives it no points', function () {
    $result = ($this->check)(['Q102' => 'Ghana']);

    expect($result->ok)->toBeFalse()
        ->and(rules($result))->toContain(FindingRule::MovementMismatch)
        ->and($result->points)->toBeNull();
});

it('tells Guinea-Bissau and Guinea apart', function () {
    expect(rules(($this->check)(['Q102' => 'Guinea-Bissau'], 'guinea-conakry')))->toContain(FindingRule::MovementMismatch)
        ->and(rules(($this->check)(['Q102' => 'Guinea-Bissau'], 'guinea-bissau')))->not->toContain(FindingRule::MovementMismatch)
        ->and(rules(($this->check)(['Q102' => 'Guinea Conakry'], 'guinea-conakry')))->not->toContain(FindingRule::MovementMismatch);
});

it('refuses a spreadsheet that is not the OHA form, naming what it found', function () {
    $junk = new Spreadsheet;
    $junk->getActiveSheet()->setTitle('Sheet1')->setCellValue('A1', 'hello');
    $path = tempnam(sys_get_temp_dir(), 'junk').'.xlsx';
    (new Xlsx($junk))->save($path);

    $result = app(FormChecker::class)->check(app(FormReader::class)->read($path));
    unlink($path);

    expect($result->ok)->toBeFalse()
        ->and($result->findings[0]->rule)->toBe(FindingRule::MissingSheet)
        ->and($result->findings[0]->hint)->toContain('Sheet1');
});

it('maps every finding to a data-quality dimension', function () {
    $result = ($this->check)([]);

    expect(collect($result->findings)->every(fn ($f) => $f->rule->dimension() !== null))->toBeTrue()
        ->and(collect($result->findings)->where('severity', FindingSeverity::Missing)->every(fn ($f) => $f->ref !== null))->toBeTrue();
});
