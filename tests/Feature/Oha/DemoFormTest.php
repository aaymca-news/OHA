<?php

use App\Actions\Oha\UploadForm;
use App\Demo\DemoForm;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Models\HealthBand;
use App\Models\Movement;
use App\Models\User;
use App\Oha\CheckResult;
use App\Oha\FormChecker;
use App\Oha\FormReader;
use App\Oha\ReadForm;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

/*
 * The demo site's invented OHA forms: the official blank form filled in by DemoForm.
 * Each must be read and accepted by the platform exactly as a real form is.
 */

beforeEach(function () {
    $this->seed(MovementsSeeder::class);
});

/**
 * Generates a demo form for a movement, reads and checks it, and deletes the file.
 *
 * @return array{0: ReadForm, 1: CheckResult}
 */
function demoFormChecked(string $slug, float $strength, int $seed): array
{
    $movement = Movement::query()->where('slug', $slug)->firstOrFail();
    $path = DemoForm::make($movement->name, $movement->country, 2026, $strength, $seed);

    try {
        $read = app(FormReader::class)->read($path);
    } finally {
        @unlink($path);
    }

    return [$read, app(FormChecker::class)->check($read, $movement)];
}

/** The percentage of the 84 points a check scored. */
function demoPercent(CheckResult $result): float
{
    return array_sum(array_filter((array) $result->points)) / 84 * 100;
}

it('makes a form the platform accepts with no errors and no gaps', function (string $slug, float $strength, int $seed) {
    [$read, $result] = demoFormChecked($slug, $strength, $seed);

    expect($result->ok)->toBeTrue()
        ->and($result->withSeverity(FindingSeverity::Error))->toBe([])
        ->and(array_map(fn ($f) => $f->message, $result->withSeverity(FindingSeverity::Missing)))->toBe([])
        ->and($read->interpreted)->toBe([])
        ->and($read->wrongUnits)->toBe([])
        ->and($read->answers['Q102'])->toBe(Movement::query()->where('slug', $slug)->value('country'))
        // Every sheet has areas of improvement, for the report and the ODP.
        ->and(array_filter(array_map(fn ($c) => $c['text'], $read->comments)))->toHaveCount(9);
})->with([
    'weak Zambia' => ['zambia', 0.15, 11],
    'middling Ghana' => ['ghana', 0.5, 22],
    'strong Kenya' => ['kenya', 0.85, 33],
    'weak Togo' => ['togo', 0.2, 44],
    'strong Nigeria' => ['nigeria', 0.9, 55],
    'middling Malawi' => ['malawi', 0.6, 66],
]);

it('scores higher the stronger the movement, in the band intended', function (int $seed) {
    $scores = [];
    foreach ([0.15, 0.5, 0.85] as $strength) {
        $scores[(string) $strength] = demoPercent(demoFormChecked('zambia', $strength, $seed)[1]);
    }

    expect($scores['0.15'])->toBeLessThan($scores['0.5'])
        ->and($scores['0.5'])->toBeLessThan($scores['0.85'])
        ->and(HealthBand::forPercentage($scores['0.15'], rated: true)->code)->toBeIn(['critical', 'atrisk'])
        ->and(HealthBand::forPercentage($scores['0.5'], rated: true)->code)->toBe('developing')
        ->and(HealthBand::forPercentage($scores['0.85'], rated: true)->code)->toBeIn(['strong', 'excellent']);
})->with([1, 2, 3]);

it('gives the same form for the same seed, and another for another seed', function () {
    [$first] = demoFormChecked('ghana', 0.5, 7);
    [$again] = demoFormChecked('ghana', 0.5, 7);
    [$other] = demoFormChecked('ghana', 0.5, 8);

    expect($again->answers)->toBe($first->answers)
        ->and($again->comments)->toBe($first->comments)
        ->and($again->signoff)->toBe($first->signoff)
        ->and($other->answers)->not->toBe($first->answers);
});

it('is taken by the real upload, ready to submit, with no findings that block or need answers', function () {
    Storage::fake('oha');
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $assessor = User::factory()->create();
    $assessor->assignedMovements()->attach($zambia);
    $form = Journey::open($assessor, $zambia, 'Feb 2026', Carbon::parse('2026-02-01'))->form()->firstOrFail();

    $path = DemoForm::make($zambia->name, $zambia->country, 2026, 0.7, 2026);
    try {
        $upload = app(UploadForm::class)->handle($form, $path, 'Zambia YMCA OHA Form 2026.xlsx', $assessor);
    } finally {
        @unlink($path);
    }

    expect($form->refresh()->state)->toBe(ArtefactState::Ready)
        ->and($upload->findings()->whereIn('severity', [FindingSeverity::Error, FindingSeverity::Missing])->count())->toBe(0)
        ->and($upload->answers['Q101'])->toContain('Zambia')
        ->and($upload->form_meta['comments']['financial'])->not->toBeNull();
});
