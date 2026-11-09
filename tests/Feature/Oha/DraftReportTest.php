<?php

use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\UploadForm;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Oha\FormDefinition;
use App\Oha\Report\DraftReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Report\SentenceBank;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The report drafted from the OHA form: every section of the official template, facts
 * and scores straight from the form, the wording from the reviewed sentence bank, and
 * nothing the form does not say. Each draft must pass the report check as it stands.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->draft = fn ($assessment) => app(DraftReport::class)->for($assessment);
    $this->text = fn (array $draft) => DraftReport::asReadReport($draft['sections'])->text();
    $this->section = fn (array $draft, string $key) => collect($draft['sections'])->firstWhere('key', $key);
});

it('has wording for every scored question, and a headline for every category', function () {
    $scored = collect(FormDefinition::categories())->flatMap(fn ($c) => $c['questions'])
        ->filter(fn ($q) => $q->max !== null)->map(fn ($q) => $q->code)->values()->all();

    expect(array_diff($scored, array_keys(SentenceBank::ITEMS)))->toBe([])
        ->and(array_keys(SentenceBank::HEADLINES))->toEqualCanonicalizing(array_keys(FormDefinition::categories()))
        ->and(array_keys(SentenceBank::BAND_MEANING))->toEqualCanonicalizing(['excellent', 'strong', 'developing', 'atrisk', 'critical']);
    foreach (SentenceBank::ITEMS as $code => [$inPlace, $gap, $opportunity]) {
        expect($inPlace)->not->toBe('')->and($gap)->not->toBe($inPlace, $code)->and($opportunity)->toEndWith('.');
    }
});

it('drafts every section of the template from the form, and the draft passes the report check', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $draft = ($this->draft)($assessment);
    $read = DraftReport::asReadReport($draft['sections']);
    $check = app(ReportChecker::class)->check($read, $assessment);

    expect(array_column($draft['sections'], 'key'))->toBe([
        'title', 'overview', 'overall', 'scores', 'strengths', 'risks', 'opportunities', 'section_c',
        'category:financial', 'category:governance', 'category:constitution', 'category:me', 'category:strategy',
        'category:diversity', 'category:comms', 'category:property', 'category:staff',
    ])
        ->and($check['findings'])->toBe([])
        // The overall score is the form's, to the point.
        ->and($check['stated']['pct'])->toBe(round(58 * 100 / 84, 2))
        ->and($draft['form_upload_id'])->toBe($this->j->form($assessment)->currentUpload()->value('id'));

    $title = ($this->section)($draft, 'title');
    expect($title['lines'][1])->toBe('Zambia YMCA · February 2026')
        ->and($title['lines'][2])->toBe('Report by: Tendai Moyo, AAYMCA');

    // The score table is the form's, and locked.
    $scores = ($this->section)($draft, 'scores');
    expect($scores['locked'])->toBeTrue()
        ->and(collect($scores['rows'])->sum('points'))->toEqual(58)
        ->and($scores['overall']['band_label'])->toBe('Developing');
});

it('writes what is in place, the gaps and the opportunities, each strategic-plan participant said once', function () {
    $draft = ($this->draft)($this->j->assessmentAt('form_uploaded'));
    $text = ($this->text)($draft);
    $finance = ($this->section)($draft, 'category:financial');

    expect($text)->toContain('Zambia YMCA scored 12 of 20 points (60.00%) in Financial Stability, which is Developing.')
        ->and($finance['text'])->toContain('a reliance on one source for 77% of its income')
        ->and($finance['items'])->toContain('Build a cash reserve, aiming for at least six months of operating costs.')
        ->and($text)->toContain('a strategic plan shaped with its staff, board members')
        ->and($text)->not->toContain('a strategic plan, a strategic plan')
        ->and($text)->not->toContain('[To complete');
});

it('picks the strengths from the strongest categories and the risks from the weakest', function () {
    $draft = ($this->draft)($this->j->assessmentAt('form_uploaded'));
    $strengths = implode(' ', ($this->section)($draft, 'strengths')['items']);
    $risks = implode(' ', ($this->section)($draft, 'risks')['items']);

    // Zambia: Strategic Planning (100%) and Governance (92%) are strengths; Communications (40%) and Staff (40%) risks.
    expect($strengths)->toContain('Clear strategic direction')->toContain('Effective governance')
        ->and($strengths)->not->toContain('Low visibility')
        ->and($risks)->toContain('Low visibility and public accountability')->toContain('Human resource capacity')
        ->and($risks)->not->toContain('Unclear strategic direction');
});

it('leaves what the form does not answer as "To complete", and skips what does not apply', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $upload = $this->j->form($assessment)->currentUpload()->firstOrFail();
    $upload->update(['answers' => ['Q412' => null, 'Q203' => 'Not Applicable', 'Q104' => null] + $upload->answers]);

    $draft = ($this->draft)($assessment->refresh());
    $text = ($this->text)($draft);

    expect(($this->section)($draft, 'category:constitution')['text'])->toContain('[To complete: the form does not answer Q412.')
        ->and($text)->not->toContain('conflict of interest policy')
        ->and($text)->not->toContain('internal financial controls')
        ->and(($this->section)($draft, 'overview')['text'])->toContain('Its headquarters are at [To complete: the address].');
});

it('needs the OHA form first', function () {
    $assessment = $this->j->assessmentAt('opened');

    expect(fn () => ($this->draft)($assessment))->toThrow(WorkflowRuleBroken::class, 'once the OHA form is uploaded and read');
});

it('drafts each real 2026 return so that it passes the report check, its score the form’s own', function (string $prefix, string $slug, int $total) {
    $movement = Movement::query()->where('slug', $slug)->first() ?? Movement::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' YMCA', 'country' => ucwords(str_replace('-', ' ', $slug))]);
    $this->j->admin->assignedMovements()->syncWithoutDetaching([$movement->id]);
    $assessment = app(OpenAssessment::class)->handle($this->j->admin, $movement, 'Feb 2026', Carbon::parse('2026-02-01'));
    app(UploadForm::class)->handle($this->j->form($assessment), ohaFormPath($prefix), "{$prefix}.xlsx", $this->j->admin);

    $draft = ($this->draft)($assessment->refresh());
    $read = DraftReport::asReadReport($draft['sections']);
    $check = app(ReportChecker::class)->check($read, $assessment);

    // Nothing missing but what the form leaves for the assessors to write ("To complete").
    expect(collect($check['findings'])->reject(fn ($f) => $f->ref === 'r:todo')->values()->all())->toBe([])
        ->and(collect($check['findings'])->contains(fn ($f) => $f->ref === 'r:todo'))->toBe(str_contains($read->text(), '[To complete'))
        ->and(collect(($this->section)($draft, 'scores')['rows'])->sum('points'))->toEqual($total);
})->with([
    'Ghana' => ['GH26', 'ghana', 51],
    'Madagascar' => ['MDG26', 'madagascar', 64],
    'South Africa' => ['SA26', 'south-africa', 45],
    'Zambia' => ['ZAM26', 'zambia', 58],
    'Zimbabwe' => ['ZIM26', 'zimbabwe', 51],
]);
