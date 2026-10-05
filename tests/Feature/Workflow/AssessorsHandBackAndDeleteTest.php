<?php

use App\Actions\Oha\AssignAssessors;
use App\Actions\Oha\DeleteAssessment;
use App\Actions\Oha\HandBackAssessment;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\MovementStatus;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('writes a reassignment into the audit trail of the assessment under way, and tells both people', function () {
    $assessment = $this->j->assessmentAt('form_submitted');
    $newcomer = User::factory()->create(['name' => 'Samuel Mwangi']);

    app(AssignAssessors::class)->handle($this->j->admin, $this->j->zambia, [$newcomer->id]);

    $event = AuditEvent::query()->where('assessment_id', $assessment->id)->where('action', 'assessment.assessors_changed')->firstOrFail();
    expect($event->payload['added_names'])->toBe(['Samuel Mwangi'])
        ->and($event->payload['removed_names'])->toBe(['Tendai Moyo'])
        ->and($event->actor_id)->toBe($this->j->admin->id)
        ->and(Notification::sent($this->j->assessor, WorkflowNotice::class)->pluck('subject'))->toContain('You no longer assess Zambia YMCA')
        ->and(Notification::sent($newcomer, WorkflowNotice::class)->pluck('subject'))->toContain('You are assigned to assess Zambia YMCA');

    $this->actingAs($this->j->admin)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'audit']))
        ->assertSee('Added Samuel Mwangi.')->assertSee('Removed Tendai Moyo.');
});

it('shows plainly when no assessor was ever assigned, who assesses now, and the history', function () {
    $page = fn ($movement) => $this->actingAs($this->j->admin)->get(route('movements.show', $movement));

    $page($this->j->ghana)->assertSee('No assessor has ever been assigned to Ghana YMCA.')->assertSee('Assign an assessor');

    app(AssignAssessors::class)->handle($this->j->admin, $this->j->ghana, [$this->j->otherStaff->id]);
    $page($this->j->ghana)->assertSee('Aminata Diallo')->assertSee('Current assessor')->assertSee('Reassign the assessor')->assertSee('Assignment history (1)');

    app(AssignAssessors::class)->handle($this->j->admin, $this->j->ghana, []);
    $page($this->j->ghana)->assertSee('Nobody is assessing Ghana YMCA at the moment.')->assertSee('Last: Aminata Diallo');
});

it('lets an assessor stop working on an assessment, and asks the Administrators to reassign it', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');

    expect(fn () => app(HandBackAssessment::class)->handle($assessment, $this->j->assessor, ' '))->toThrow(WorkflowRuleBroken::class, 'Say why');

    app(HandBackAssessment::class)->handle($assessment, $this->j->assessor, 'Moving to the West Africa zone.');

    expect($this->j->assessor->canAssess($this->j->zambia))->toBeFalse()
        ->and(Assessment::query()->find($assessment->id))->not->toBeNull()
        ->and(AuditEvent::query()->where('assessment_id', $assessment->id)->where('action', 'assessment.handed_back')->value('payload')['reason'])->toBe('Moving to the West Africa zone.');

    foreach ([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $admin) {
        Notification::assertSentTo($admin, WorkflowNotice::class, fn ($n) => $n->subject === 'Assessor needed: Zambia YMCA'
            && str_contains($n->body, 'Nobody is assessing it now. Please assign an assessor.'));
    }
});

it('lets only an assessor on the movement hand back, and only while work remains', function () {
    $open = $this->j->assessmentAt('form_uploaded');
    $done = $this->j->assessmentAt('odp_signed');

    expect(Gate::forUser($this->j->otherStaff)->inspect('handBack', $open)->message())->toContain('Only an assessor on this movement')
        ->and(Gate::forUser($this->j->admin)->allows('handBack', $open))->toBeFalse()
        ->and(Gate::forUser($this->j->assessor)->inspect('handBack', $done)->message())->toContain('complete');
});

it('lets only the Super Administrator delete an assessment, and never an Administrator', function () {
    $assessment = $this->j->assessmentAt('form_approved');

    foreach ([$this->j->admin, $this->j->assessor, $this->j->chair] as $notAllowed) {
        expect(fn () => app(DeleteAssessment::class)->handle($assessment, $notAllowed, 'Zambia YMCA', 'Test'))
            ->toThrow(WorkflowRuleBroken::class, 'Only the Super Administrator can delete an assessment.');
    }

    expect(fn () => app(DeleteAssessment::class)->handle($assessment, $this->j->superAdmin, 'Zambia', 'Test'))
        ->toThrow(WorkflowRuleBroken::class, 'Type the movement’s name, Zambia YMCA');
});

it('deletes the whole assessment so the movement is not assessed again, keeping the audit trail', function () {
    $assessment = $this->j->assessmentAt('odp_signed');
    $files = FormUpload::query()->pluck('path')->merge(Document::query()->pluck('path'));
    $before = AuditEvent::query()->where('assessment_id', $assessment->id)->count();

    expect(MovementStatus::query()->where('slug', 'zambia')->value('band_code'))->toBe('developing');

    app(DeleteAssessment::class)->handle($assessment, $this->j->superAdmin, 'zambia ymca', 'Entered against the wrong year.');

    expect(Assessment::query()->find($assessment->id))->toBeNull()
        ->and(MovementStatus::query()->where('slug', 'zambia')->value('band_code'))->toBe('notstarted')
        ->and(MovementStatus::query()->where('slug', 'zambia')->value('has_odp'))->toBeFalse()
        ->and(AuditEvent::query()->where('assessment_id', $assessment->id)->count())->toBe($before + 1)
        ->and(AuditEvent::query()->where('action', 'assessment.deleted')->value('payload'))->toMatchArray(['reason' => 'Entered against the wrong year.', 'period' => 'Feb 2026']);

    foreach ($files as $path) {
        expect(Storage::disk('oha')->exists($path))->toBeFalse();
    }
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn ($n) => $n->subject === 'Assessment deleted: Zambia YMCA');
});

it('removes only the deleted assessment’s files, never another assessment’s', function () {
    $first = $this->j->assessmentAt('form_uploaded');
    $second = $this->j->assessmentAt('form_uploaded');
    $firstPath = FormUpload::query()->whereHas('artefact', fn ($q) => $q->where('assessment_id', $first->id))->value('path');
    $secondPath = FormUpload::query()->whereHas('artefact', fn ($q) => $q->where('assessment_id', $second->id))->value('path');

    app(DeleteAssessment::class)->handle($first, $this->j->superAdmin, 'Zambia YMCA', 'Duplicate.');

    expect(Storage::disk('oha')->exists($firstPath))->toBeFalse()
        ->and(Storage::disk('oha')->exists($secondPath))->toBeTrue()
        ->and(Assessment::query()->find($second->id))->not->toBeNull();
});

it('offers hand-back to the assessor and deletion to the Super Administrator on the page', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $url = route('assessments.show', $assessment);

    $this->actingAs($this->j->assessor)->get($url)->assertSee('Stop working on this assessment')->assertDontSee('Delete this assessment');
    $this->actingAs($this->j->admin)->get($url)->assertDontSee('Delete this assessment');
    $this->actingAs($this->j->superAdmin)->get($url)->assertSee('Delete this assessment');

    $this->actingAs($this->j->admin)->delete(route('assessments.destroy', $assessment), ['confirm_name' => 'Zambia YMCA', 'reason' => 'x'])
        ->assertSessionHasErrors(['action' => 'Only the Super Administrator can delete an assessment.']);
    $this->actingAs($this->j->superAdmin)->delete(route('assessments.destroy', $assessment), ['confirm_name' => 'Zambia YMCA', 'reason' => 'Opened by mistake.'])
        ->assertRedirect(route('movements.show', $this->j->zambia));
    $this->actingAs($this->j->assessor)->get($url)->assertNotFound();
});

it('shows missing information and data quality only to the movement’s assessors and the Administrators', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $form = route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']);

    foreach ([$this->j->assessor, $this->j->admin, $this->j->superAdmin] as $user) {
        $this->actingAs($user)->get($form)->assertSee('What the check found')->assertSee('Data quality')->assertSee('missing from the form');
    }

    // Other staff see the approved form and its score, not the movement's gaps.
    $this->actingAs($this->j->otherStaff)->get($form)->assertOk()->assertSee('Score check')
        ->assertDontSee('What the check found')->assertDontSee('Data quality')->assertDontSee('missing from the form');
});
