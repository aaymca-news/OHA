<?php

use App\Actions\Oha\EditTimeline;
use App\Actions\Oha\ResolveGap;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AssessmentMilestone;
use App\Models\AuditEvent;
use App\Models\FormFinding;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

function gap(string $ref): FormFinding
{
    return FormFinding::query()->where('ref', $ref)->latest('id')->firstOrFail();
}

it('closes a gap only with a note, and only by the movement’s assessors or an Administrator', function () {
    $this->j->assessmentAt('form_submitted');
    $stranger = User::factory()->create();

    expect(fn () => app(ResolveGap::class)->resolve(gap('q:Q246'), $this->j->assessor, ''))
        ->toThrow(WorkflowRuleBroken::class, 'Say how it was resolved')
        ->and(fn () => app(ResolveGap::class)->resolve(gap('q:Q246'), $stranger, 'Confirmed by email.'))
        ->toThrow(WorkflowRuleBroken::class, 'assigned assessors or the Administrators');

    app(ResolveGap::class)->resolve(gap('q:Q246'), $this->j->assessor, 'NGS confirmed ZMW 40,000 by email.');
    // An Administrator can resolve on any assessment, without being assigned to it.
    app(ResolveGap::class)->resolve(gap('q:Q911'), $this->j->admin, 'Property list received separately.');

    expect(gap('q:Q246')->resolved_note)->toBe('NGS confirmed ZMW 40,000 by email.')
        ->and(gap('q:Q911')->resolved_by)->toBe($this->j->admin->id);

    app(ResolveGap::class)->reopen(gap('q:Q246'), $this->j->assessor);
    expect(gap('q:Q246')->resolved_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'gap.reopened')->first()->payload['previous_note'])->toBe('NGS confirmed ZMW 40,000 by email.');
});

it('lets an item for review be marked as reviewed with a note, which changes no answer', function () {
    $this->j->assessmentAt('form_uploaded');
    $warning = FormFinding::query()->where('severity', 'warning')->firstOrFail();
    $answers = $warning->formUpload->answers;

    app(ResolveGap::class)->resolve($warning, $this->j->assessor, 'Checked with the NGS: the head count is right.');

    expect($warning->refresh()->resolved_note)->toBe('Checked with the NGS: the head count is right.')
        ->and($warning->formUpload->refresh()->answers)->toBe($answers);
});

it('lets a set deadline drive the due date, and tells the assessor when someone else sets it', function () {
    $assessment = $this->j->assessmentAt('opened');

    app(EditTimeline::class)->setGateDeadline($assessment, 'form', '2026-12-01', $this->j->admin);

    $form = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'form')->firstOrFail();
    expect($form->due_on->toDateString())->toBe('2026-12-01')
        ->and($form->set_by_hand)->toBeTrue()
        ->and(WorkItem::query()->findOrFail($assessment->id)->due_on->toDateString())->toBe('2026-12-01')
        ->and(Notification::sent($this->j->assessor, WorkflowNotice::class)->pluck('subject'))->toContain('Timeline changed: Zambia YMCA');

    app(EditTimeline::class)->setGateDeadline($assessment, 'form', null, $this->j->assessor);
    expect(AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'form')->firstOrFail()->set_by_hand)->toBeFalse();
});

it('gives every assessment its four gate milestones, from the SLA', function () {
    $assessment = $this->j->assessmentAt('opened');
    $opened = $assessment->opened_at->copy()->startOfDay();

    $milestones = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->orderBy('sort_order')->get();

    expect($milestones->pluck('gate_code')->all())->toBe(['form', 'report', 'odp', 'board'])
        ->and($milestones->first()->due_on->toDateString())->toBe($opened->copy()->addDays(21)->toDateString())
        ->and($milestones->last()->due_on->toDateString())->toBe($opened->copy()->addDays(98)->toDateString());
});

it('adds, completes and removes custom steps, but never a gate', function () {
    $assessment = $this->j->assessmentAt('opened');
    $editor = app(EditTimeline::class);

    $step = $editor->addStep($assessment, 'Field visit', '2026-10-15', $this->j->assessor);
    $editor->updateStep($step, '2026-10-20', true, $this->j->assessor);

    expect($step->refresh()->done_on)->not->toBeNull()
        ->and($step->due_on->toDateString())->toBe('2026-10-20')
        ->and(fn () => $editor->addStep($assessment, 'Debrief', '2026-02-30', $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'real dates');

    $editor->removeStep($step, $this->j->assessor);
    expect($assessment->timelineItems()->count())->toBe(0);
});

it('refuses timeline changes from staff not on the movement', function () {
    $assessment = $this->j->assessmentAt('opened');

    expect(fn () => app(EditTimeline::class)->setGateDeadline($assessment, 'form', '2026-12-01', User::factory()->create()))
        ->toThrow(WorkflowRuleBroken::class, 'Only the assessors on this movement and the Administrators');
});

it('marks a gate done when its document is, with no date typed by anyone', function () {
    $assessment = $this->j->assessmentAt('form_approved');

    $form = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'form')->firstOrFail();
    $report = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'report')->firstOrFail();

    expect($form->done_at)->not->toBeNull()
        ->and($form->completed_late)->toBeFalse()
        ->and($report->done_at)->toBeNull();
});
