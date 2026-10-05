<?php

use App\Enums\ArtefactState;
use App\Models\ArtefactStatus;
use App\Models\AssessmentMilestone;
use App\Models\AuditEvent;
use App\Models\MovementStatus;
use App\Models\WorkItem;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('takes Zambia from an opened assessment to an ODP signed by the Board Chairperson', function () {
    $assessment = $this->j->assessmentAt('odp_signed');

    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Approved)
        ->and($this->j->report($assessment)->state)->toBe(ArtefactState::Approved)
        ->and($this->j->odp($assessment)->state)->toBe(ArtefactState::Approved)
        ->and(ArtefactStatus::query()->findOrFail($this->j->odp($assessment)->id)->validated)->toBeTrue()
        // Stage 1 complete: nothing is waiting on anyone, and the movement has a validated ODP.
        ->and(WorkItem::query()->find($assessment->id))->toBeNull()
        ->and(MovementStatus::query()->where('slug', 'zambia')->firstOrFail()->has_odp)->toBeTrue()
        ->and(AssessmentMilestone::query()->where('assessment_id', $assessment->id)->count())->toBe(4)
        ->and(AssessmentMilestone::query()->where('assessment_id', $assessment->id)->whereNull('done_at')->count())->toBe(0);
});

it('writes one audit row for every step, in order', function () {
    $assessment = $this->j->assessmentAt('odp_signed');

    expect(AuditEvent::query()->where('assessment_id', $assessment->id)->orderBy('id')->pluck('action')->all())->toBe([
        'assessment.opened', 'form.uploaded', 'form.submitted', 'form.approved',
        'report.version_uploaded', 'report.submitted', 'report.approved',
        'odp.version_uploaded', 'odp.drive_linked', 'odp.submitted', 'odp.approved',
        'odp.signed_by_board',
    ]);
});

it('moves the holder along: assessor, Administrators, Board Chairperson, then done', function () {
    $holder = fn (string $stage) => WorkItem::query()->find($this->j->assessmentAt($stage)->id)?->holder_role->value;

    expect($holder('form_uploaded'))->toBe('assessor')
        ->and($holder('form_submitted'))->toBe('approver')
        ->and($holder('report_uploaded'))->toBe('assessor')
        ->and($holder('odp_approved'))->toBe('board')
        ->and($holder('odp_signed'))->toBeNull();
});

it('tells each person only what concerns them', function () {
    $this->j->assessmentAt('odp_approved');

    $subjects = fn ($user) => Notification::sent($user, WorkflowNotice::class)->pluck('subject')->all();

    expect($subjects($this->j->admin))->toContain('Approval needed: OHA form, Zambia YMCA', 'Approval needed: ODP (version 1), Zambia YMCA')
        ->and($subjects($this->j->superAdmin))->toContain('Approval needed: Health assessment report (version 1), Zambia YMCA')
        ->and($subjects($this->j->chair))->toBe([
            'Now available: Health assessment report (version 1), Zambia YMCA',
            'Please sign: ODP (version 1), Zambia YMCA',
        ])
        ->and($subjects($this->j->ghanaChair))->toBe([])
        ->and($subjects($this->j->otherStaff))->toBe([])
        ->and($subjects($this->j->assessor))->toContain('Approved: OHA form, Zambia YMCA', 'Approved: ODP (version 1), Zambia YMCA');
});
