<?php

use App\Enums\FindingSeverity;
use App\Models\Movement;
use App\Oha\FindingRule;
use App\Oha\FormChecker;
use App\Oha\FormReader;
use Database\Seeders\MovementsSeeder;

/*
 * The real Zambia YMCA 2026 OHA form, read from its actual bytes.
 */

beforeEach(function () {
    $this->seed(MovementsSeeder::class);
    $this->zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $this->read = app(FormReader::class)->read(zambiaFormPath());
    $this->result = app(FormChecker::class)->check($this->read, $this->zambia);
});

it('accepts the form, with no blocking error', function () {
    expect($this->result->ok)->toBeTrue()
        ->and($this->result->withSeverity(FindingSeverity::Error))->toBe([]);
});

it('scores 58 of 84 points, every category matching the form’s own total', function () {
    expect($this->result->points)->toBe([
        'financial' => 12, 'governance' => 11, 'constitution' => 11, 'me' => 5,
        'strategy' => 12, 'diversity' => 3, 'comms' => 2, 'property' => null, 'staff' => 2,
    ])
        ->and(array_sum(array_filter($this->result->points)))->toBe(58)
        ->and(collect($this->result->findings)->where('rule', FindingRule::TotalMismatch))->toBeEmpty();
});

it('reads the movement facts and sign-off from General Information', function () {
    expect($this->result->cover['legal_name'])->toBe('National Council of Zambia YMCAs')
        ->and($this->result->cover['structure'])->toBe('Single Entity')
        ->and($this->result->cover['signoff']['Chair / President of the Board'])->toBe('Yes')
        ->and($this->result->cover['signoff']['National General Secretary (NGS)'])->toBe('Yes')
        ->and($this->result->cover['consent'])->toBeFalse();
});

it('finds exactly the three gaps in the Zambia form', function () {
    expect($this->result->gapRefs())->toEqualCanonicalizing(['q:Q246', 'q:Q911', 'c:financial']);
});

it('does not demand questions the answers make unnecessary', function () {
    $gaps = $this->result->gapRefs();

    // Q239 (reserve) is No, so Q240/Q241 are not needed; a single entity skips the federated questions.
    expect($gaps)->not->toContain('q:Q240')->not->toContain('q:Q241')
        ->not->toContain('q:Q424')->not->toContain('q:Q815')
        // Q914 is satisfied by the note beside Q913.
        ->not->toContain('q:Q914');
});

it('reads the form’s own prompt as a blank answer', function () {
    expect($this->read->answers['Q424'])->toBeNull();
});

it('flags the staff head count that does not add up', function () {
    $warning = collect($this->result->findings)->firstWhere('rule', FindingRule::Inconsistent);

    expect($warning->message)->toContain('22')->toContain('33');
});

it('captures the improvement comments the report is built from', function () {
    expect($this->read->comments['governance']['text'])->toMatch('/succession/i');
});
