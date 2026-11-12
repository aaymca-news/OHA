<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Enums\ArtefactState;
use App\Models\OdpTask;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Who assesses (decided 12 Nov 2026): every Administrator and the Super Administrator can
 * do all an assessor does, on any movement, without being assigned. Only they assign staff
 * to movements; staff never assign themselves.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->admins = [$this->j->admin, $this->j->superAdmin];
});

it('lets the Administrators do an assessor’s work on any movement, unassigned', function () {
    foreach ($this->admins as $admin) {
        expect($admin->isAssignedTo($this->j->zambia))->toBeFalse()
            ->and($admin->canAssess($this->j->zambia))->toBeTrue()
            ->and($admin->can('openAssessment', $this->j->zambia))->toBeTrue();
    }
    // Staff not assigned cannot.
    expect($this->j->otherStaff->canAssess($this->j->zambia))->toBeFalse()
        ->and($this->j->otherStaff->can('openAssessment', $this->j->zambia))->toBeFalse();

    // Stage 1: an unassigned Administrator opens it and uploads the form.
    $assessment = Journey::open($this->j->secondAdmin, $this->j->zambia, 'Feb 2026', now());
    expect($this->j->secondAdmin->can('upload', $this->j->form($assessment)))->toBeTrue()
        ->and($this->j->otherStaff->can('upload', $this->j->form($assessment)))->toBeFalse();
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 OHA Form.xlsx', $this->j->secondAdmin);

    // Not assigned, so not "their own work" straight away: it is submitted, then approved like anyone's.
    expect($this->j->secondAdmin->can('approve', $this->j->form($assessment)))->toBeFalse();
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->secondAdmin, acknowledgeGaps: true, reason: 'Following up.');
    app(ApproveArtefact::class)->handle($this->j->form($assessment), $this->j->admin);
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Approved);

    // Handing back is giving up an assignment: only someone assigned.
    expect($this->j->admin->can('handBack', $assessment))->toBeFalse()
        ->and($this->j->assessor->can('handBack', $assessment))->toBeTrue();
});

it('lets the Administrators run the follow-up as an assessor does', function () {
    $assessment = $this->j->assessmentAt('closed');
    $task = OdpTask::query()->create(['assessment_id' => $assessment->id, 'source' => OdpTask::TYPED, 'ref' => '1.1', 'task' => 'Approve a plan']);

    foreach ($this->admins as $admin) {
        foreach (['recordFollowUp', 'runFollowUps', 'reviewOdp', 'carryOdpTasks', 'closeFollowUp', 'viewFollowUp', 'editTimeline'] as $ability) {
            expect($admin->can($ability, $assessment))->toBeTrue("{$admin->role->value}: {$ability}");
        }
        expect($admin->can('note', $task))->toBeTrue()->and($admin->can('drop', $task))->toBeTrue();
    }
    expect($this->j->otherStaff->can('recordFollowUp', $assessment))->toBeFalse()
        ->and($this->j->otherStaff->can('note', $task))->toBeFalse();

    // An unassigned Administrator holds a whole follow-up: opens it, records the meeting, closes it.
    $this->j->holdFollowUp($assessment, 1, by: $this->j->superAdmin);
    expect($assessment->followUps()->first()->recorder->is($this->j->superAdmin))->toBeTrue();
});

it('lets only the Administrators assign staff to a movement: staff never assign themselves', function () {
    $staff = User::factory()->create(['name' => 'Kwame Asante']);

    $this->actingAs($staff)->put(route('movements.assessors', $this->j->zambia), ['assessor_ids' => [$staff->id]])
        ->assertSessionHasErrors();
    expect($staff->refresh()->isAssignedTo($this->j->zambia))->toBeFalse()
        ->and($staff->can('assignAssessors', $this->j->zambia))->toBeFalse();

    $this->actingAs($this->j->admin)->put(route('movements.assessors', $this->j->zambia), ['assessor_ids' => [$this->j->assessor->id, $staff->id]])
        ->assertSessionHasNoErrors();
    expect($staff->refresh()->isAssignedTo($this->j->zambia))->toBeTrue()
        ->and($staff->canAssess($this->j->zambia))->toBeTrue();
});
