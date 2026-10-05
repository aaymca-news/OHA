<?php

use App\Models\Movement;
use App\Oha\Answer;
use App\Oha\FindingRule;
use App\Oha\FormChecker;
use App\Oha\FormDefinition;
use App\Oha\FormReader;
use App\Oha\Profile;
use Database\Seeders\MovementsSeeder;

/*
 * The five real 2026 returns in "O.H.A Excel Forms" (Ghana, Madagascar, South Africa,
 * Zambia, Zimbabwe), checked against what each form printed, and the OW2026 template.
 */

beforeEach(function () {
    $this->seed(MovementsSeeder::class);
    $this->check = function (string $prefix, string $slug) {
        $read = app(FormReader::class)->read(ohaFormPath($prefix));

        return [$read, app(FormChecker::class)->check($read, Movement::query()->where('slug', $slug)->firstOrFail())];
    };
});

dataset('returns', [
    'Ghana' => ['GH26', 'ghana', 51],
    'Madagascar' => ['MDG26', 'madagascar', 64],
    'South Africa' => ['SA26', 'south-africa', 45],
    'Zambia' => ['ZAM26', 'zambia', 58],
    'Zimbabwe' => ['ZIM26', 'zimbabwe', 51],
]);

it('scores every return exactly as the form itself printed it', function (string $prefix, string $slug, int $total) {
    [$read, $result] = ($this->check)($prefix, $slug);

    expect($result->ok)->toBeTrue()
        ->and(array_sum(array_filter($result->points)))->toBe($total)
        ->and(collect($result->findings)->where('rule', FindingRule::TotalMismatch)->all())->toBe([]);

    foreach ($read->printedTotals as $category => $printed) {
        expect($result->points[$category])->toEqual($printed, "{$prefix} {$category}");
    }
})->with('returns');

it('counts Q424 (membership criteria), as the form’s Constitution total does', function () {
    [, $result] = ($this->check)('SA26', 'south-africa');

    // South Africa answered Q424 "Yes": the form prints 10 for Constitution.
    expect($result->points['constitution'])->toBe(10)
        ->and(FormDefinition::attainablePoints()['constitution'])->toBe(20);
});

it('reads percentages from a percentage-formatted cell as whole numbers', function () {
    [$read, $result] = ($this->check)('GH26', 'ghana');

    // Ghana typed 73% into a cell formatted as a percentage, which stores 0.7332.
    expect($read->answers['Q229'])->toBe(73.32)
        ->and(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::SumMismatch && str_contains($f->message, 'Expenditure')))->toBeFalse();
});

it('reads money typed with its currency, and flags only text that is not an amount', function () {
    [, $result] = ($this->check)('GH26', 'ghana');
    $nonNumeric = collect($result->findings)->where('rule', FindingRule::NonNumeric)->pluck('questionCode')->all();

    // "USD 528,359.77" is an amount; "54 branches, 30 active" is not.
    expect($nonNumeric)->not->toContain('Q209', 'Q211', 'Q212', 'Q213')
        ->and($nonNumeric)->toContain('Q118');
});

it('flags a scoring cell whose formula was typed over', function () {
    [$read, $result] = ($this->check)('GH26', 'ghana');

    expect($read->overwrittenScoring)->toBe(['Q1001' => 'E2', 'Q1002' => 'E3', 'Q1003' => 'E4'])
        ->and(collect($result->findings)->where('rule', FindingRule::FormulaOverwritten)->pluck('questionCode')->all())->toBe(['Q1001', 'Q1002', 'Q1003']);
});

it('flags an operating balance that is not income minus expenditure', function () {
    [, $result] = ($this->check)('ZIM26', 'zimbabwe');

    // Zimbabwe spent more than it earned but reported a surplus of the same size.
    expect(collect($result->findings)->contains(fn ($f) => $f->rule === FindingRule::Inconsistent && $f->questionCode === 'Q213'))->toBeTrue();
});

it('accepts "Not Applicable" where the template offers it', function () {
    [$read] = ($this->check)('ZAM26', 'zambia');
    $withNa = app(FormChecker::class)->check($read->withAnswers(['Q203' => 'Not Applicable', 'Q244' => 'Not Applicable']), Movement::query()->where('slug', 'zambia')->firstOrFail());

    expect(collect($withNa->findings)->where('rule', FindingRule::InvalidOption)->pluck('questionCode')->all())->not->toContain('Q203', 'Q244');
});

it('reads the OW2026 template as the blank form it is', function () {
    $read = app(FormReader::class)->read(ohaFormPath('OW2026'));
    $result = app(FormChecker::class)->check($read, Movement::query()->where('slug', 'zambia')->firstOrFail());

    expect($read->version)->toBe(FormDefinition::VERSION)
        ->and($read->overwrittenScoring)->toBe([])
        ->and($result->ok)->toBeFalse()
        ->and(collect($result->findings)->pluck('rule'))->toContain(FindingRule::EmptyForm);
});

it('turns a return into a profile, comparing ratios rather than currencies', function () {
    $zimbabwe = new Profile(app(FormReader::class)->read(ohaFormPath('ZIM26'))->answers);
    $ghana = (new Profile(app(FormReader::class)->read(ohaFormPath('GH26'))->answers))->toArray();
    $zim = $zimbabwe->toArray();

    expect($zim['finance']['balance'])->toEqual(-9743)
        ->and($zim['finance']['margin_pct'])->toBe(-8.0)
        ->and($zim['people']['volunteers'])->toEqual(248)
        ->and($ghana['international_pct'])->toEqual(87)
        ->and($ghana['income_mix'])->toEqual(['services' => 13, 'public' => 0, 'membership' => 0, 'giving' => 0, 'private' => 55, 'ymca' => 32])
        ->and(count(array_filter($ghana['vision_2030'])))->toBe(7);
});

it('reads money the way movements write it', function (mixed $typed, ?float $amount) {
    expect(Answer::amount($typed))->toBe($amount);
})->with([
    ['USD 90,000', 90000.0], ['USD 440,765.164', 440765.164], ['USD, 87,584.12', 87584.12],
    ['ZAR 418 332.92', 418332.92], ['ZAR - 155 673.00', -155673.0], ['R5 723 904,40', 5723904.4],
    ['1.234,56', 1234.56], ['1,234', 1234.0], [7563369, 7563369.0],
    ['54 branches, 30 active', null], ['385 volunteers', null], ['No Minimum', null],
]);
