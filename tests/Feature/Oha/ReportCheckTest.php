<?php

use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadReportVersion;
use App\Enums\ArtefactState;
use App\Notifications\WorkflowNotice;
use App\Oha\FindingRule;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Report\ReportReader;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\WordDocument;

/*
 * The report check: an uploaded report is read and checked against the structure of
 * an OHA analysis report and against the movement's own OHA form. It only flags.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->check = fn ($assessment) => app(ReportChecker::class)->check(
        ReadReport::fromArray($this->j->report($assessment)->latestVersion()->firstOrFail()->extracted),
        $assessment,
    );
    $this->rules = fn (array $result) => collect($result['findings'])->map(fn ($f) => $f->rule->value.($f->categoryCode ? ':'.$f->categoryCode : ''))->all();
});

function realReport(string $name): string
{
    $path = base_path('../National Movement Assessment Reports/'.$name);
    if (! is_file($path)) {
        test()->markTestSkipped("{$name} is not available.");
    }

    return $path;
}

it('finds nothing wrong in a complete report whose score agrees with the form', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport());

    $result = ($this->check)($assessment);

    expect($result['findings'])->toBe([])
        ->and($result['stated'])->toBe(['pct' => 69.0, 'points' => 58.0, 'out_of' => 84.0])
        ->and($result['baseline']['source'])->toBe('approved');
});

it('flags missing sections, categories and their opportunities', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        '### Key Risks' => null, '- Limited reserves.' => null, '- Low staffing.' => null,
        '### 7. Communications and Branding' => null,
        '### 8. Property Management' => '### 8. Property Management',
    ]));
    // Drop the opportunities under Staff and Volunteer Development.
    $lines = WordDocument::zambiaReport(['### Key Risks' => null, '- Limited reserves.' => null, '- Low staffing.' => null, '### 7. Communications and Branding' => null]);
    $staff = array_search('### 9. Staff and Volunteer Development', $lines, true);
    array_splice($lines, $staff + 2, 3);
    $this->j->uploadWordReport($assessment, $lines);

    expect(($this->rules)(($this->check)($assessment)))->toEqualCanonicalizing([
        'report_section_missing', 'report_category_missing:comms', 'report_category_missing:staff',
    ]);
});

it('flags a score that is not the form’s, and one out of the wrong total', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        'Zambia YMCA attained an overall score of 58/84, which is 69.0%. '.str_repeat('The movement has a clear plan, an active board and a growing programme, with room to strengthen its systems, reserves and reporting over the coming year. ', 3) => 'The overall score was 52/80 translating to 65%. '.str_repeat('The movement has a clear plan and an active board. ', 12),
    ]));

    $result = ($this->check)($assessment);
    $messages = collect($result['findings'])->pluck('message')->all();

    expect($messages)->toContain('The report scores out of 80; the OHA form scores out of 84.')
        ->and($messages)->toContain('The report gives an overall score of 65%, but the OHA form gives 58 of 84 points (69%).');
});

it('flags a title that names another movement or another year', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        '### ORGANISATIONAL HEALTH ASSESSMENT REPORT ZAMBIA YMCA February 2026: Report By: Osborne and Lavine AAYMCA' => '### ORGANISATIONAL HEALTH ASSESSMENT REPORT MALAWI YMCA February 2025: Report By: Osborne and Lavine AAYMCA',
    ]));

    $messages = collect(($this->check)($assessment)['findings'])->pluck('message')->all();

    expect($messages)->toContain('The report’s title names Malawi, not Zambia.')
        ->and($messages)->toContain('The report’s title says 2025, but this assessment is for 2026.');
});

it('says when the category scores are only in a picture', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(), picture: true);

    expect(($this->rules)(($this->check)($assessment)))->toBe(['report_score_unreadable']);
});

it('checks against the uploaded form before it is approved', function () {
    $assessment = $this->j->assessmentAt('form_submitted');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport());

    $result = ($this->check)($assessment);

    expect($result['baseline'])->toMatchArray(['points' => 58.0, 'available' => 84, 'source' => 'uploaded'])
        ->and($result['findings'])->toBe([]);
});

it('says a PDF without text cannot be checked, and never stops the report', function () {
    $assessment = $this->j->assessmentAt('form_submitted');
    $this->j->uploadReport($assessment, 'scanned');

    expect(($this->rules)(($this->check)($assessment)))->toBe(['report_unreadable']);

    app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->j->assessor);
    expect($this->j->report($assessment)->state)->toBe(ArtefactState::PendingApproval);
    Notification::assertSentTo($this->j->admin, WorkflowNotice::class, fn ($n) => str_contains($n->body, 'The report check flagged 1 item to look at before approving.'));
});

it('catches the real Zambia report’s score, which is not the form’s', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    app(UploadReportVersion::class)->handle($this->j->report($assessment), realReport('OHA Analysis Report Zambia YMCA 2026.docx'), 'Zambia report.docx', $this->j->assessor);

    $result = ($this->check)($assessment);

    expect($result['stated']['pct'])->toBe(65.86)
        ->and(($this->rules)($result))->toEqualCanonicalizing(['report_score_mismatch', 'report_score_unreadable']);
});

it('reads the Zimbabwe report’s score as an average of the category percentages', function () {
    $read = app(ReportReader::class)->read(realReport('Zimbabwe OHA Analysis Report 2026-4.docx'), 'docx');

    expect(app(ReportChecker::class)->statedScore($read)['pct'])->toBe(63.45)
        ->and(count(array_filter($read->paragraphs, [ReadReport::class, 'isTitle'])))->toBeGreaterThan(9);
});

it('shows the report check to the assessors and the Administrators only', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(['### Key Risks' => null]));
    $tab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']);

    foreach ([$this->j->assessor, $this->j->admin] as $user) {
        $this->actingAs($user)->get($tab)->assertSee('Report check · version 2')->assertSee('The report has no section on the key risks.');
    }
    $this->actingAs($this->j->otherStaff)->get($tab)->assertOk()->assertDontSee('Report check');
    $this->actingAs($this->j->chair)->get($tab)->assertOk()->assertDontSee('Report check');
});

it('keeps what it read with the version, so the check follows the current form', function () {
    $assessment = $this->j->assessmentAt('form_submitted');
    $version = $this->j->uploadWordReport($assessment, WordDocument::zambiaReport());

    expect($version->extracted['format'])->toBe('docx')
        ->and(count($version->extracted['paragraphs']))->toBeGreaterThan(40)
        ->and(FindingRule::ReportScoreMismatch->dimension()->value)->toBe('accuracy');
});
