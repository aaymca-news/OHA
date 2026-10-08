<?php

use App\Actions\Oha\FixReport;
use App\Actions\Oha\ReviewReportFinding;
use App\Actions\Oha\SubmitForApproval;
use App\Enums\ArtefactState;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Report\ReportFixes;
use App\Oha\Report\ReportReader;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;
use Tests\Support\WordDocument;

/*
 * What the report check finds is fixed in the report itself: from what the platform
 * already knows (the OHA form's score, the assessment's period), or as typed. Each fix
 * is a new version made by the platform; that is what is previewed and downloaded.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->findings = fn (Assessment $a) => app(ReportChecker::class)->check(
        ReadReport::fromArray($this->j->report($a)->latestVersion()->firstOrFail()->extracted), $a)['findings'];
    $this->refs = fn (Assessment $a) => collect(($this->findings)($a))->pluck('ref')->filter()->values()->all();
});

it('treats strengths and opportunities as the same section, either one will do', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    // A strong movement written up by its strengths only: no "Opportunities" section.
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        '### Opportunities for Growth' => null, '- Property development.' => null, '- Fundraising systems.' => null,
    ]));
    expect(($this->refs)($assessment))->not->toContain('r:strengths');

    // A developing one by its opportunities only: no "Strengths" section.
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        '### Key Strengths' => null, '- Strong strategic alignment.' => null, '- Effective governance.' => null,
    ]));
    expect(($this->refs)($assessment))->not->toContain('r:strengths');
});

it('does not call the score missing when the form has it, and writes it in from the form', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        'Zambia YMCA attained an overall score of 58/84, which is 69.0%. '.str_repeat('The movement has a clear plan, an active board and a growing programme, with room to strengthen its systems, reserves and reporting over the coming year. ', 3) => 'The movement is growing.',
    ]));

    $score = collect(($this->findings)($assessment))->firstWhere('ref', 'r:score');
    expect($score->severity->value)->toBe('warning')
        ->and($score->message)->toContain('the OHA form gives 58 of 84 points (69%)')
        ->and(ReportFixes::for($score)['kind'])->toBe('form');

    $version = app(FixReport::class)->handle($this->j->report($assessment), $this->j->assessor, 'r:score');

    expect($version->source)->toBe(DocumentSource::Platform)
        ->and($version->versionNumber())->toBe(2)
        ->and($version->note)->toContain('stated the overall score from the OHA form, 58/84 points (69%)')
        ->and(($this->refs)($assessment))->not->toContain('r:score');

    // The file itself says it: what is previewed and downloaded.
    $read = app(ReportReader::class)->read(Storage::disk('oha')->path($version->path), 'docx');
    expect($read->text())->toContain('Overall score: 58/84 points (69%), from the approved OHA form.');
});

it('writes a typed section, the author and a category’s opportunities into the report', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $lines = WordDocument::zambiaReport([
        '### ORGANISATIONAL HEALTH ASSESSMENT REPORT ZAMBIA YMCA February 2026: Report By: Osborne and Lavine AAYMCA' => '### ORGANISATIONAL HEALTH ASSESSMENT REPORT ZAMBIA YMCA February 2026',
        '### Key Risks' => null, '- Limited reserves.' => null, '- Low staffing.' => null,
    ]);
    $staff = array_search('### 9. Staff and Volunteer Development', $lines, true);
    array_splice($lines, $staff + 2, 3);
    $this->j->uploadWordReport($assessment, $lines);
    expect(($this->refs)($assessment))->toContain('r:author', 'r:risks', 'r:growth:staff');

    $fix = app(FixReport::class);
    $fix->handle($this->j->report($assessment), $this->j->assessor, 'r:author', ['value' => 'Tendai Moyo, AAYMCA']);
    $fix->handle($this->j->report($assessment), $this->j->admin, 'r:risks', ['value' => "- Limited reserves.\n- Low staffing at branch level."]);
    $last = $fix->handle($this->j->report($assessment), $this->j->assessor, 'r:growth:staff', ['value' => "Recruit volunteer coordinators\nTrain branch staff"]);

    expect(($this->refs)($assessment))->not->toContain('r:author', 'r:risks', 'r:growth:staff')
        ->and($last->versionNumber())->toBe(4);
    $text = app(ReportReader::class)->read(Storage::disk('oha')->path($last->path), 'docx')->text();
    expect($text)->toContain('Report by: Tendai Moyo, AAYMCA', 'Key risks', '• Low staffing at branch level.', '• Recruit volunteer coordinators');
});

it('fills in everything it can from the form in one version, and refuses empty typing', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport([
        'Zambia YMCA attained an overall score of 58/84, which is 69.0%. '.str_repeat('The movement has a clear plan, an active board and a growing programme, with room to strengthen its systems, reserves and reporting over the coming year. ', 3) => 'Zambia YMCA attained an overall score of 61.9%.',
    ]), picture: true);
    expect(($this->refs)($assessment))->toContain('r:score-fix', 'r:catscores');

    expect(fn () => app(FixReport::class)->handle($this->j->report($assessment), $this->j->assessor, 'r:risks', ['value' => 'x']))
        ->toThrow(WorkflowRuleBroken::class, 'no longer flagged');

    $version = app(FixReport::class)->fromForm($this->j->report($assessment), $this->j->assessor);
    expect(($this->refs)($assessment))->not->toContain('r:score-fix', 'r:catscores')
        ->and($version->note)->toContain('corrected the figure in the text');
});

it('fixes a report while it waits for approval, keeping it with the Administrators, and nothing once the ODP is signed', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(), picture: true);
    app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->j->assessor);

    app(FixReport::class)->fromForm($this->j->report($assessment), $this->j->assessor);
    expect($this->j->report($assessment)->state)->toBe(ArtefactState::PendingApproval)
        ->and($this->j->report($assessment)->versions()->count())->toBe(1);

    $signed = $this->j->assessmentAt('odp_signed');
    expect(fn () => app(ReviewReportFinding::class)->mark($this->j->report($signed), $this->j->assessor, str_repeat('a', 32), 'Something', 'Checked.'))
        ->toThrow(WorkflowRuleBroken::class, 'frozen');
});

it('lets only the assessors and the Administrators mark a report finding as reviewed', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(), picture: true);
    $finding = collect(($this->findings)($assessment))->firstWhere('ref', 'r:catscores');

    app(ReviewReportFinding::class)->mark($this->j->report($assessment), $this->j->otherStaff->fresh(), ReportFixes::key($finding), $finding->message, 'Scores are in the table on page 3.');
})->throws(WorkflowRuleBroken::class, 'Only the assessors');

it('lets an assessor mark a finding as reviewed, and shows what can be fixed', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(), picture: true);
    $finding = collect(($this->findings)($assessment))->firstWhere('ref', 'r:catscores');

    $tab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']);
    $this->actingAs($this->j->assessor)->get($tab)->assertSee('Add it from the OHA form')->assertSee('Mark as reviewed');

    app(ReviewReportFinding::class)->mark($this->j->report($assessment), $this->j->assessor, ReportFixes::key($finding), $finding->message, 'Scores are in the table on page 3.');

    $this->actingAs($this->j->admin)->get($tab)->assertSee('Reviewed by Tendai Moyo')->assertSee('Scores are in the table on page 3.');
    expect($this->j->report($assessment)->state)->toBe(ArtefactState::Drafted);
});

it('fixes the report from the page: from the form, and as typed', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $this->j->uploadWordReport($assessment, WordDocument::zambiaReport(['### Key Risks' => null, '- Limited reserves.' => null, '- Low staffing.' => null]), picture: true);
    $report = $this->j->report($assessment);

    $this->actingAs($this->j->admin)->post(route('report-check.fix', $report), ['ref' => 'r:risks', 'value' => "- Limited reserves\n- Low staffing"])
        ->assertRedirect()->assertSessionHas('status', 'Written into the report as version 2. The check has run again on it.');
    $this->actingAs($this->j->assessor)->post(route('report-check.from-form', $report))
        ->assertSessionHasNoErrors();

    expect(($this->refs)($assessment))->not->toContain('r:risks', 'r:catscores');
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']))
        ->assertSee('Fixed in the platform')->assertSee('Fixed in the platform: wrote each category’s score from the OHA form into its section.');
});
