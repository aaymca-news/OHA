<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SendBack;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadReportVersion;
use App\Enums\ArtefactState;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AssessmentMilestone;
use App\Models\AuditEvent;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

/*
 * The report is written outside the system and uploaded as Word or PDF. Every
 * version is kept. A new version can follow at any time except while one is with
 * the Administrators; after approval it goes back for approval, and until then
 * staff and the Chairperson keep seeing the last approved version.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('saves an uploaded report as version 1, stored unchanged, ready to submit', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $report = $this->j->report($assessment);
    $version = $report->latestVersion()->firstOrFail();

    expect($report->state)->toBe(ArtefactState::Drafted)
        ->and($version->versionNumber())->toBe(1)
        ->and($version->format->value)->toBe('pdf')
        ->and($version->isApproved())->toBeFalse()
        ->and(Storage::disk('oha')->exists($version->path))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'report.version_uploaded')->value('payload')['version'])->toBe(1)
        ->and(Gate::forUser($this->j->assessor)->allows('submit', $report))->toBeTrue();
});

it('keeps every version, and submits and approves the newest', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $this->j->uploadReport($assessment, 'second draft', note: 'Added the March board elections.');
    app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->report($assessment), $this->j->admin);

    $versions = $this->j->report($assessment)->versions()->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->isApproved())->toBeFalse()
        ->and($versions[1]->isApproved())->toBeTrue()
        ->and($versions[1]->approved_by)->toBe($this->j->admin->id)
        ->and($versions[1]->note)->toBe('Added the March board elections.');
});

it('accepts only Word and PDF, and refuses a file identical to an earlier version', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $report = $this->j->report($assessment);

    expect(fn () => app(UploadReportVersion::class)->handle($report, Journey::reportFile('x'), 'report.xlsx', $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'Word (.docx) or PDF')
        ->and(fn () => $this->j->uploadReport($assessment, 'Zambia OHA report, first version'))
        ->toThrow(WorkflowRuleBroken::class, 'identical to version 1');

    $this->j->uploadReport($assessment, 'as word', name: 'Zambia report.docx');
    expect($report->latestVersion()->firstOrFail()->format->value)->toBe('docx');
});

it('lets only the movement’s assessors upload the report', function () {
    $assessment = $this->j->assessmentAt('form_approved');

    foreach ([$this->j->otherStaff, $this->j->admin, $this->j->chair] as $notAssessor) {
        expect(fn () => $this->j->uploadReport($assessment, 'not mine', $notAssessor))
            ->toThrow(WorkflowRuleBroken::class);
    }
});

it('holds new versions while one is with the Administrators, and asks for one after a send-back', function () {
    $assessment = $this->j->assessmentAt('report_submitted');

    expect(fn () => $this->j->uploadReport($assessment, 'while pending'))
        ->toThrow(WorkflowRuleBroken::class, 'with the Administrators for approval');

    app(SendBack::class)->handle($this->j->report($assessment), $this->j->admin, 'Correct the Financial Stability score.');

    expect(fn () => app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'upload the revised version first');

    $this->j->uploadReport($assessment, 'revised');
    app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->j->assessor);

    expect($this->j->report($assessment)->state)->toBe(ArtefactState::PendingApproval);
});

it('re-opens an approved report for a new version, showing the approved one until the new one is approved', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $report = $this->j->report($assessment);
    $approved = $report->approvedVersion()->firstOrFail();

    $newer = $this->j->uploadReport($assessment, 'post-approval correction', note: 'Corrected the membership figures.');
    $report->refresh();

    expect($report->state)->toBe(ArtefactState::Drafted)
        ->and($report->isPublished())->toBeTrue()
        ->and($report->approvedVersion()->firstOrFail()->id)->toBe($approved->id)
        // Staff and the Chairperson see the approved version, not the draft.
        ->and(Gate::forUser($this->j->otherStaff)->allows('view', $report))->toBeTrue()
        ->and(Gate::forUser($this->j->otherStaff)->allows('download', $approved))->toBeTrue()
        ->and(Gate::forUser($this->j->otherStaff)->allows('download', $newer))->toBeFalse()
        ->and(Gate::forUser($this->j->chair)->allows('download', $approved))->toBeTrue()
        ->and(Gate::forUser($this->j->chair)->allows('download', $newer))->toBeFalse()
        ->and(Gate::forUser($this->j->assessor)->allows('download', $newer))->toBeTrue()
        // The ODP already under way is not locked again.
        ->and($this->j->odp($assessment)->status->effective_state)->toBe('drafted');

    app(SubmitForApproval::class)->handle($report, $this->j->assessor);
    app(ApproveArtefact::class)->handle($report->refresh(), $this->j->secondAdmin);

    expect($report->approvedVersion()->firstOrFail()->id)->toBe($newer->id)
        ->and(Gate::forUser($this->j->otherStaff)->allows('download', $newer->refresh()))->toBeTrue();
});

it('clears the report gate on its first approved version, and keeps it cleared', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    $done = fn () => AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'report')->value('done_at');
    $first = $done();

    $this->j->uploadReport($assessment, 'later correction');

    expect($first)->not->toBeNull()
        ->and($done())->toEqual($first);
});

it('shows the report to the Board Chairperson once a version is approved, like the approved form', function () {
    $assessment = $this->j->assessmentAt('report_submitted');

    // The form is approved by now: the Chairperson sees it, and so the assessment, but not the report yet.
    expect(Gate::forUser($this->j->chair)->allows('view', $this->j->report($assessment)))->toBeFalse()
        ->and(Gate::forUser($this->j->chair)->allows('view', $this->j->form($assessment)))->toBeTrue()
        ->and(Gate::forUser($this->j->chair)->allows('view', $assessment))->toBeTrue();

    app(ApproveArtefact::class)->handle($this->j->report($assessment), $this->j->admin);

    expect(Gate::forUser($this->j->chair)->allows('view', $this->j->report($assessment)))->toBeTrue()
        ->and(Gate::forUser($this->j->ghanaChair)->allows('view', $this->j->report($assessment)))->toBeFalse()
        ->and(Gate::forUser($this->j->ghanaChair)->allows('view', $assessment))->toBeFalse()
        ->and(Document::query()->count())->toBe(1);
});
