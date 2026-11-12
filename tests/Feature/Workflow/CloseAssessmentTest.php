<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SubmitForApproval;
use App\Enums\ArtefactState;
use App\Enums\Stage;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\WorkflowNotice;
use App\Oha\FollowUpSchedule;
use App\Queries\MyWork;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Closing the assessment (12 Nov 2026): Stage 1 ends when its assessors, an Administrator
 * or the Super Administrator close it, once the ODP is approved. That freezes the OHA form,
 * report and ODP, sets Baseline 1 and starts the follow-up clock. The Board Chairperson's
 * signature is optional: before or after, it only validates the ODP.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
});

$group = fn (User $user, string $key): ?array => collect(app(MyWork::class)->for($user))->firstWhere('key', $key);

it('closes an assessment once its ODP is approved, without any signature', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    expect(WorkItem::query()->find($assessment->id)->holder_role->value)->toBe('closer')
        ->and(Stage::of($assessment))->toBe(Stage::Close);

    $this->travelTo(now()->setDate(2026, 3, 10));
    $this->j->close($assessment, firstFollowUpOn: '2026-07-15');
    $assessment->refresh();

    expect($assessment->isClosed())->toBeTrue()
        ->and($assessment->closer->is($this->j->assessor))->toBeTrue()
        ->and($this->j->odp($assessment)->signature()->exists())->toBeFalse()
        ->and(WorkItem::query()->find($assessment->id))->toBeNull()
        ->and(Stage::of($assessment))->toBe(Stage::Complete)
        ->and(Assessment::query()->done()->pluck('id')->all())->toBe([$assessment->id])
        ->and(AuditEvent::query()->where('action', 'assessment.closed')->count())->toBe(1)
        // No fixed follow-up dates: the first, agreed with the movement, given on closing; the others not set yet.
        ->and(collect(FollowUpSchedule::for($assessment))->map(fn ($m) => $m['due_on']?->toDateString())->all())
        ->toBe(['2026-07-15', null, null])
        ->and(collect(FollowUpSchedule::for($assessment))->pluck('state')->unique()->all())->toBe([FollowUpSchedule::UPCOMING]);
});

it('lets its assessors, an Administrator or the Super Administrator close it, nobody else', function () {
    $assessment = $this->j->assessmentAt('odp_approved');

    foreach ([$this->j->otherStaff, $this->j->chair, $this->ngs, $this->j->ghanaChair] as $who) {
        expect(fn () => $this->j->close($assessment, $who))->toThrow(WorkflowRuleBroken::class, 'closed by the movement’s assessors or an Administrator');
    }
    foreach ([$this->j->assessor, $this->j->admin, $this->j->superAdmin] as $who) {
        expect($this->j->assessor->fresh()->can('close', $assessment->fresh()))->toBeTrue()
            ->and($who->can('close', $assessment->fresh()))->toBeTrue();
    }
    $this->j->close($assessment, $this->j->admin);

    expect($assessment->refresh()->closer->is($this->j->admin))->toBeTrue()
        ->and(fn () => $this->j->close($assessment, $this->j->superAdmin))->toThrow(WorkflowRuleBroken::class, 'already closed');
});

it('waits for the ODP’s approval, and while anything is with the Administrators', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');
    expect(fn () => $this->j->close($assessment))->toThrow(WorkflowRuleBroken::class, 'once its ODP is approved');

    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    // A newer ODP version handed in after approval: closing waits for the decision.
    $this->j->uploadOdp($assessment, 'Zambia ODP, second version');
    app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor);
    expect(fn () => $this->j->close($assessment))->toThrow(WorkflowRuleBroken::class, 'waiting for the Administrators’ approval');

    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    expect($this->j->close($assessment)->isClosed())->toBeTrue();

    $this->j->assessor->assignedMovements()->attach($this->j->ghana);
    $early = Journey::open($this->j->assessor, $this->j->ghana, 'Mar 2026', now());
    expect(fn () => $this->j->close($early))->toThrow(WorkflowRuleBroken::class, 'once its ODP is approved');
});

it('freezes the OHA form, report and ODP on closing, not on signing', function () {
    $assessment = $this->j->assessmentAt('odp_approved');

    // Signed before closing: nothing is frozen yet, a newer ODP version can still be added.
    $this->j->sign($this->j->odp($assessment));
    expect($this->j->uploadOdp($assessment, 'Zambia ODP, corrected after signing'))->not->toBeNull();
    app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);

    $this->j->close($assessment);
    expect(fn () => $this->j->uploadOdp($assessment, 'after closing'))->toThrow(WorkflowRuleBroken::class, Assessment::FROZEN)
        ->and(fn () => $this->j->uploadReport($assessment, 'after closing'))->toThrow(WorkflowRuleBroken::class, Assessment::FROZEN)
        ->and($this->j->odp($assessment)->state)->toBe(ArtefactState::Approved);
});

it('still lets the Board Chairperson sign after closing, which only validates the ODP', function () {
    $assessment = $this->j->assessmentAt('closed');
    expect($this->j->chair->can('sign', $this->j->odp($assessment)))->toBeTrue();

    $signature = $this->j->sign($this->j->odp($assessment));

    expect($signature->document_id)->toBe($this->j->odp($assessment)->approvedVersion()->value('id'))
        ->and($assessment->refresh()->closer->is($this->j->assessor))->toBeTrue();
});

it('never reminds the Board Chairperson to sign: it is optional, in My Work only', function () use ($group) {
    $assessment = $this->j->assessmentAt('odp_approved');
    $subjects = Notification::sent($this->j->chair, WorkflowNotice::class)->pluck('subject')->all();

    expect($subjects)->not->toContain('Please sign: ODP (version 1), Zambia YMCA')
        ->and($subjects)->toContain('Now available: ODP (version 1), Zambia YMCA');

    // In My Work, quietly: no target date, not on the badge, no "waiting on you".
    $sign = $group($this->j->chair, 'sign')['items'];
    expect($sign)->toHaveCount(1)
        ->and($sign[0]['days_left'])->toBeNull()
        ->and(app(MyWork::class)->actionableCount($this->j->chair))->toBe(0);
    $this->actingAs($this->j->chair)->get(route('my-work'))->assertOk()
        ->assertSee('ODP to sign (optional)')->assertSee('Optional')->assertDontSee('1 waiting on you');
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertDontSee('ready for your signature')->assertDontSee('need you in My Work')->assertDontSee('needs you in My Work');

    // The movement's people hear when follow-up begins; the Board Chairperson does not.
    Notification::fake();
    $this->j->close($assessment);
    expect(Notification::sent($this->ngs, WorkflowNotice::class)->pluck('subject')->all())->toBe(['Follow-up begins: Zambia YMCA'])
        ->and(Notification::sent($this->j->chair, WorkflowNotice::class))->toHaveCount(0);
});

it('puts an assessment ready to close in My Work: the assessor’s own, and the Administrators’', function () use ($group) {
    $assessment = $this->j->assessmentAt('odp_approved');

    $mine = collect($group($this->j->assessor, 'started')['items'])->firstWhere('ready', true);
    expect($mine['actionable'])->toBeTrue()
        ->and(collect($group($this->j->admin, 'close')['items'])->pluck('artefact.assessment_id')->all())->toBe([$assessment->id])
        ->and(app(MyWork::class)->actionableCount($this->j->admin))->toBeGreaterThan(0);

    $this->actingAs($this->j->assessor)->get(route('my-work', ['group' => 'started']))->assertOk()
        ->assertSee('Ready to close')->assertSee('Close the assessment');
    $this->actingAs($this->j->admin)->get(route('my-work', ['group' => 'close']))->assertOk()
        ->assertSee('Zambia YMCA · Feb 2026')->assertSee('Close the assessment');

    $this->j->close($assessment);
    expect($group($this->j->admin, 'close')['items'])->toBe([]);
});

it('closes from the assessment page, for those who may', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $page = route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']);

    $this->actingAs($this->j->otherStaff)->get($page)->assertOk()->assertDontSee('Close the assessment');
    $this->actingAs($this->j->otherStaff)->post(route('assessments.close', $assessment))->assertSessionHasErrors();
    expect($assessment->refresh()->isClosed())->toBeFalse();

    $this->actingAs($this->j->assessor)->get($page)->assertOk()
        ->assertSee('Close the assessment to set Baseline 1')->assertSee('Close the assessment');
    $this->actingAs($this->j->assessor)->post(route('assessments.close', $assessment))
        ->assertRedirect(route('follow-up.show', $assessment))->assertSessionHasNoErrors();

    expect($assessment->refresh()->isClosed())->toBeTrue();
    $this->actingAs($this->j->assessor)->get($page)->assertOk()
        ->assertSee('Baseline 1')->assertSee('The assessment is closed, so this ODP is kept as approved.')->assertDontSee('Close the assessment to set Baseline 1');
});
