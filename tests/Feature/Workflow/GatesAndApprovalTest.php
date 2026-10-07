<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\SendBack;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Enums\ArtefactState;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\ArtefactStatus;
use App\Models\AuditEvent;
use App\Models\WorkItem;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('keeps the report locked until an OHA form is uploaded and read, but not until it is approved', function () {
    $assessment = $this->j->assessmentAt('opened');

    expect(fn () => $this->j->uploadReport($assessment, 'too early'))
        ->toThrow(WorkflowRuleBroken::class, 'unlocks once the OHA form is uploaded and read');

    // Uploaded and submitted, not yet approved: the staff can already go on to the report.
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'f.xlsx', $this->j->assessor);
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true);
    $this->j->uploadReport($assessment, 'while the form waits for approval');

    expect($this->j->form($assessment)->state)->toBe(ArtefactState::PendingApproval)
        ->and($this->j->report($assessment)->state)->toBe(ArtefactState::Drafted);
});

it('keeps the report locked after a refused form upload', function () {
    $this->j->assessor->assignedMovements()->attach($this->j->ghana);
    $assessment = app(OpenAssessment::class)->handle($this->j->assessor, $this->j->ghana, 'Feb 2026', Carbon::parse('2026-02-01'));
    // The Zambia form, uploaded for Ghana, is refused.
    app(UploadForm::class)->handle($assessment->form()->firstOrFail(), zambiaFormPath(), 'f.xlsx', $this->j->assessor);

    expect($assessment->form()->firstOrFail()->state)->toBe(ArtefactState::RulesFailed)
        ->and(fn () => $this->j->uploadReport($assessment, 'refused form'))->toThrow(WorkflowRuleBroken::class, 'unlocks once the OHA form');
});

it('keeps the ODP locked until a version of the report is saved', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $odpState = fn () => ArtefactStatus::query()->where('assessment_id', $assessment->id)->where('kind', 'odp')->value('effective_state');

    expect($odpState())->toBe('locked')
        ->and(fn () => $this->j->uploadOdp($assessment, 'too early'))
        ->toThrow(WorkflowRuleBroken::class, 'unlocks once a version of the report is saved');

    $this->j->uploadReport($assessment, 'first version');

    expect($odpState())->toBe('not_started');
});

it('opens the ODP once the report is saved, without waiting for either approval', function () {
    $assessment = $this->j->assessmentAt('form_submitted');
    $this->j->uploadReport($assessment, 'saved while the form waits for approval');
    $this->j->uploadOdp($assessment, 'first draft, while the form and report wait');

    expect($this->j->form($assessment)->state)->toBe(ArtefactState::PendingApproval)
        ->and($this->j->report($assessment)->state)->toBe(ArtefactState::Drafted)
        ->and($this->j->odp($assessment)->state)->toBe(ArtefactState::Drafted);
});

it('needs the ODP’s Google Drive link with its first version, and refuses links that are not a document', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');

    expect(fn () => $this->j->uploadOdp($assessment, 'no link', driveUrl: null))
        ->toThrow(WorkflowRuleBroken::class, 'Add the link to the ODP in Google Drive')
        ->and(fn () => $this->j->uploadOdp($assessment, 'folder', driveUrl: 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWx'))
        ->toThrow(WorkflowRuleBroken::class, 'link to a folder')
        ->and(fn () => $this->j->uploadOdp($assessment, 'slides', driveUrl: 'https://docs.google.com/presentation/d/1AbCdEfGhIjKlMnOpQrStUvWx/edit'))
        ->toThrow(WorkflowRuleBroken::class, 'Google Slides')
        ->and(fn () => $this->j->uploadOdp($assessment, 'elsewhere', driveUrl: 'https://example.com/odp.docx'))
        ->toThrow(WorkflowRuleBroken::class, 'starts with https://docs.google.com/')
        ->and($this->j->odp($assessment)->versions()->count())->toBe(0);

    $this->j->uploadOdp($assessment, 'with a link');
    // Linked once: later versions need no link.
    $this->j->uploadOdp($assessment, 'second', driveUrl: null);
    $odp = $this->j->odp($assessment);

    expect($odp->drive_file_id)->toBe('1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr')
        ->and($odp->drive_url)->toBe(Journey::DRIVE_URL)
        ->and($odp->drive_linked_by)->toBe($this->j->assessor->id)
        ->and($odp->versions()->count())->toBe(2);
});

it('lets any Administrator approve, except the one who submitted it', function () {
    $this->j->admin->assignedMovements()->attach($this->j->zambia);

    $byAdmin = $this->j->assessmentAt('opened');
    app(UploadForm::class)->handle($this->j->form($byAdmin), zambiaFormPath(), 'f.xlsx', $this->j->admin);
    app(SubmitForApproval::class)->handle($this->j->form($byAdmin), $this->j->admin, acknowledgeGaps: true);

    expect(fn () => app(ApproveArtefact::class)->handle($this->j->form($byAdmin), $this->j->admin))
        ->toThrow(WorkflowRuleBroken::class, 'You submitted this, and nobody approves their own work. Another Administrator must approve it.');

    app(ApproveArtefact::class)->handle($this->j->form($byAdmin), $this->j->superAdmin);

    $staffs = $this->j->assessmentAt('form_submitted');
    app(ApproveArtefact::class)->handle($this->j->form($staffs), $this->j->secondAdmin);

    expect($this->j->form($byAdmin)->approved_by)->toBe($this->j->superAdmin->id)
        ->and($this->j->form($staffs)->approved_by)->toBe($this->j->secondAdmin->id);
});

it('tells every Administrator except the submitter that something awaits approval', function () {
    $this->j->assessmentAt('form_submitted');
    $told = fn ($user) => Notification::sent($user, WorkflowNotice::class)->pluck('subject')->contains('Approval needed: OHA form, Zambia YMCA');

    expect($told($this->j->admin))->toBeTrue()
        ->and($told($this->j->secondAdmin))->toBeTrue()
        ->and($told($this->j->superAdmin))->toBeTrue()
        ->and($told($this->j->assessor))->toBeFalse()
        ->and($told($this->j->otherStaff))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'form.submitted')->value('payload')['approvers'])->toHaveCount(3);
});

it('never lets staff or the Board Chairperson approve', function () {
    $form = $this->j->form($this->j->assessmentAt('form_submitted'));

    foreach ([$this->j->assessor, $this->j->otherStaff, $this->j->chair] as $notApprover) {
        expect(Gate::forUser($notApprover)->inspect('approve', $form)->message())->toBe('Only an Administrator approves.');
    }
});

it('blocks an Administrator from submitting when no other Administrator could approve it', function () {
    $this->j->secondAdmin->update(['active' => false]);
    $this->j->superAdmin->update(['active' => false]);
    $this->j->admin->assignedMovements()->attach($this->j->zambia);
    $assessment = $this->j->assessmentAt('opened');
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'f.xlsx', $this->j->admin);

    expect(fn () => app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->admin, acknowledgeGaps: true))
        ->toThrow(WorkflowRuleBroken::class, 'there is no other Administrator to approve yours');
});

it('refuses a submission when there is no Administrator', function () {
    foreach ([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $admin) {
        $admin->update(['active' => false]);
    }
    $assessment = $this->j->assessmentAt('form_uploaded');

    expect(fn () => app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true))
        ->toThrow(WorkflowRuleBroken::class, 'There is no Administrator to approve this');
});

it('asks for an acknowledgement before a form with gaps goes forward, and records it', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');

    expect(fn () => app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, '3 items are missing from the form');

    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true, reason: 'Confirming with the NGS.');

    expect($this->j->form($assessment)->gap_ack_reason)->toBe('Confirming with the NGS.');
});

it('needs a reason to send work back, and returns it to the assessor', function () {
    $assessment = $this->j->assessmentAt('form_submitted');

    expect(fn () => app(SendBack::class)->handle($this->j->form($assessment), $this->j->admin, ' '))->toThrow(WorkflowRuleBroken::class, 'Say why');

    app(SendBack::class)->handle($this->j->form($assessment), $this->j->admin, 'Q246 must be answered first.');
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Rejected)
        ->and(WorkItem::query()->findOrFail($assessment->id)->holder_role->value)->toBe('assessor');

    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'corrected.xlsx', $this->j->assessor);
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true);
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::PendingApproval);
});

it('makes a sent-back ODP be revised before it is resubmitted', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');

    expect(fn () => $this->j->uploadOdp($assessment, 'while it waits'))
        ->toThrow(WorkflowRuleBroken::class, 'with the Administrators for approval');

    app(SendBack::class)->handle($this->j->odp($assessment), $this->j->admin, 'Add the March board elections.');

    expect(fn () => app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'upload the revised version first, or change it in Google Drive');

    $this->j->uploadOdp($assessment, 'with the March board elections');
    app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor);

    expect($this->j->odp($assessment)->state)->toBe(ArtefactState::PendingApproval);
});
