<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Enums\ArtefactState;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\AuditEvent;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use App\Oha\DqaSummary;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('oha');
    $this->seed(MovementsSeeder::class);

    $this->zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $this->assessor = User::factory()->create();
    $this->assessor->assignedMovements()->attach($this->zambia);
    $this->admin = User::factory()->admin()->create();
    $this->secondAdmin = User::factory()->admin()->create();

    $this->openForm = function (?User $by = null): Artefact {
        $assessment = app(OpenAssessment::class)->handle($by ?? $this->assessor, $this->zambia, 'Feb 2026', Carbon::parse('2026-02-01'));

        return $assessment->form()->firstOrFail();
    };
    $this->upload = fn (Artefact $form, ?User $by = null) => app(UploadForm::class)
        ->handle($form, zambiaFormPath(), 'ZAM26 OHA Form.xlsx', $by ?? $this->assessor);
});

it('rates a movement only once its form is approved, and every figure updates at once', function () {
    $status = fn () => MovementStatus::query()->where('slug', 'zambia')->firstOrFail();
    expect($status()->band_code)->toBe('notstarted');

    $form = ($this->openForm)();
    ($this->upload)($form);
    app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor, acknowledgeGaps: true, reason: 'Following up with the movement.');

    // Uploaded and submitted, but not approved: still no rating.
    expect($status()->band_code)->toBe('notstarted');

    app(ApproveArtefact::class)->handle($form->refresh(), $this->admin);

    expect((float) $status()->points_achieved)->toBe(58.0)
        ->and($status()->points_available)->toBe(84)
        ->and((float) $status()->pct)->toBe(69.0)
        ->and($status()->band_code)->toBe('developing')
        ->and($status()->next_assessment_on->toDateString())->toBe('2027-08-01')
        ->and($form->refresh()->state)->toBe(ArtefactState::Approved);
});

it('stores the uploaded file unchanged and records its checksum', function () {
    $form = ($this->openForm)();
    $upload = ($this->upload)($form);

    Storage::disk('oha')->assertExists($upload->path);
    expect($upload->sha256)->toBe(hash_file('sha256', zambiaFormPath()))
        ->and(hash('sha256', Storage::disk('oha')->get($upload->path)))->toBe($upload->sha256)
        ->and($upload->answers['Q102'])->toBe('Zambia')
        ->and($upload->findings()->count())->toBe(4)
        ->and($form->refresh()->state)->toBe(ArtefactState::Ready);
});

it('summarises the findings for the Administrators by data-quality dimension', function () {
    $upload = ($this->upload)(($this->openForm)());
    $summary = DqaSummary::of($upload->findings);

    expect($summary)->toHaveCount(6)
        ->and($summary['completeness']['verdict'])->toBe('gap')
        ->and($summary['consistency']['verdict'])->toBe('warn')
        ->and($summary['accuracy']['verdict'])->toBe('pass');

    $upload->findings()->where('severity', 'missing')->get()
        ->each->update(['resolved_by' => $this->assessor->id, 'resolved_at' => now(), 'resolved_note' => 'Confirmed with the movement.']);

    expect(DqaSummary::of($upload->findings()->get())['completeness']['verdict'])->toBe('pass');
});

it('refuses to submit a form with open gaps unless they are acknowledged', function () {
    $form = ($this->openForm)();
    ($this->upload)($form);

    expect(fn () => app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor))
        ->toThrow(WorkflowRuleBroken::class, '3 items are missing');
});

it('sends everyone’s submissions to the Administrators, never to the submitter', function () {
    $form = ($this->openForm)();
    ($this->upload)($form);
    app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor, acknowledgeGaps: true);

    $this->admin->assignedMovements()->attach($this->zambia);
    $adminsOwn = ($this->openForm)($this->admin);
    ($this->upload)($adminsOwn, $this->admin);
    app(SubmitForApproval::class)->handle($adminsOwn->refresh(), $this->admin, acknowledgeGaps: true);

    $approvers = AuditEvent::query()->where('action', 'form.submitted')->orderBy('id')->get()->map(fn ($e) => $e->payload['approvers']);

    expect($approvers[0])->toEqualCanonicalizing([$this->admin->id, $this->secondAdmin->id])
        ->and($approvers[1])->toBe([$this->secondAdmin->id]);
});

it('refuses to let staff approve, or anyone approve their own', function () {
    $this->admin->assignedMovements()->attach($this->zambia);
    $form = ($this->openForm)($this->admin);
    ($this->upload)($form, $this->admin);
    app(SubmitForApproval::class)->handle($form->refresh(), $this->admin, acknowledgeGaps: true);

    expect(fn () => app(ApproveArtefact::class)->handle($form->refresh(), $this->admin))
        ->toThrow(WorkflowRuleBroken::class, 'nobody approves their own work')
        ->and(fn () => app(ApproveArtefact::class)->handle($form->refresh(), $this->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'Only an Administrator approves');
});

it('blocks submission when there is no Administrator to approve it', function () {
    $this->admin->update(['active' => false]);
    $this->secondAdmin->update(['active' => false]);
    $form = ($this->openForm)();
    ($this->upload)($form);

    expect(fn () => app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor, acknowledgeGaps: true))
        ->toThrow(WorkflowRuleBroken::class, 'There is no Administrator');
});

it('keeps a refused upload on record, but it cannot be submitted', function () {
    $ghana = Movement::query()->where('slug', 'ghana')->firstOrFail();
    $this->assessor->assignedMovements()->attach($ghana);
    $assessment = app(OpenAssessment::class)->handle($this->assessor, $ghana, 'Feb 2026', Carbon::parse('2026-02-01'));
    $form = $assessment->form()->firstOrFail();

    $upload = ($this->upload)($form);

    expect($form->refresh()->state)->toBe(ArtefactState::RulesFailed)
        ->and($upload->findings()->where('rule', 'movement_mismatch')->exists())->toBeTrue()
        ->and(fn () => app(SubmitForApproval::class)->handle($form, $this->assessor, acknowledgeGaps: true))
        ->toThrow(WorkflowRuleBroken::class, 'refused');
});

it('refuses uploads from anyone not assigned to the movement, and after approval', function () {
    $stranger = User::factory()->create();
    $form = ($this->openForm)();

    expect(fn () => ($this->upload)($form, $stranger))->toThrow(WorkflowRuleBroken::class, 'not assigned');

    ($this->upload)($form);
    app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor, acknowledgeGaps: true);
    app(ApproveArtefact::class)->handle($form->refresh(), $this->admin);

    expect(fn () => ($this->upload)($form->refresh()))->toThrow(WorkflowRuleBroken::class, 'already approved');
});

it('records every step in the audit trail', function () {
    $form = ($this->openForm)();
    ($this->upload)($form);
    app(SubmitForApproval::class)->handle($form->refresh(), $this->assessor, acknowledgeGaps: true, reason: 'Carry forward.');
    app(ApproveArtefact::class)->handle($form->refresh(), $this->admin);

    expect(AuditEvent::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['assessment.opened', 'form.uploaded', 'form.submitted', 'form.approved'])
        ->and(AuditEvent::query()->where('action', 'form.submitted')->first()->payload['open_gaps'])->toBe(3);
});

it('lets only staff open an assessment for a movement they are assigned to', function () {
    $board = User::factory()->chair($this->zambia)->create();

    expect(fn () => app(OpenAssessment::class)->handle($board, $this->zambia, 'Feb 2026', Carbon::parse('2026-02-01')))
        ->toThrow(WorkflowRuleBroken::class)
        ->and($board->role)->toBe(Role::Board);
});
