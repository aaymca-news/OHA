<?php

use App\Actions\Oha\CarryOverTasks;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Queries\FollowUpDigest;
use App\Support\OdpTimeline;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Stage 2, step 5: the annual review of the ODP before the next assessment, and carrying
 * unfinished tasks into the next assessment so they are not lost.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('odp_signed');
    $task = fn (string $ref) => OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => $ref, 'priority' => 'GOVERNANCE', 'task' => 'Task '.$ref,
        'due_on' => today()->addMonth()->toDateString(), 'responsible' => 'The General Secretary',
    ]);
    $this->done = $task('1.1');
    $this->open = $task('1.2');
    $this->blocked = $task('1.3');
    app(ReportTaskProgress::class)->handle($this->done, $this->j->assessor, TaskProgress::Done);
    app(ReportTaskProgress::class)->handle($this->blocked, $this->ngs, TaskProgress::Blocked, 'No funds.');
});

it('lets an assessor write the review, keeping the counts as they stood, and tells the movement', function () {
    $review = app(RecordOdpReview::class)->handle($this->assessment, $this->j->assessor, 'The board was trained.', 'The fund was not raised: no donor yet.', 'Start fundraising earlier.');

    $all = $this->assessment->odpTasks()->count();
    expect($review->tasks_done)->toBe(1)->and($review->tasks_total)->toBe($all);
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'The review of'));

    // Again: corrected, not a second review.
    app(RecordOdpReview::class)->handle($this->assessment, $this->j->assessor, 'Corrected.', 'Still not raised.');
    expect($this->assessment->odpReview()->count())->toBe(1)->and($this->assessment->odpReview->achieved)->toBe('Corrected.');

    // Only the assessors write it.
    expect(fn () => app(RecordOdpReview::class)->handle($this->assessment, $this->ngs, 'x', 'y'))->toThrow(WorkflowRuleBroken::class);
});

it('carries chosen unfinished tasks into the next assessment, once it is opened', function () {
    expect(fn () => app(CarryOverTasks::class)->handle($this->assessment, $this->j->assessor, [$this->open->id]))
        ->toThrow(WorkflowRuleBroken::class, 'once the movement’s next assessment is opened');

    $next = Journey::open($this->j->assessor, $this->j->zambia, 'Mar 2028', now());
    $count = app(CarryOverTasks::class)->handle($this->assessment, $this->j->assessor, [$this->open->id, $this->blocked->id, $this->done->id]);

    // The done task is not carried; the two unfinished ones are, without a date.
    expect($count)->toBe(2);
    $carried = $next->odpTasks()->get();
    expect($carried->pluck('ref')->all())->toBe(['1.2', '1.3'])
        ->and($carried->pluck('source')->unique()->all())->toBe([OdpTask::CARRIED])
        ->and($carried->first()->due_on)->toBeNull()
        ->and($carried->first()->responsible)->toBe('The General Secretary')
        ->and($this->open->refresh()->carried_to_assessment_id)->toBe($next->id);

    // Each only once.
    expect(fn () => app(CarryOverTasks::class)->handle($this->assessment, $this->j->assessor, [$this->open->id]))->toThrow(WorkflowRuleBroken::class);

    // A newer ODP read from Excel replaces only the tasks read from the ODP: carried ones stay.
    $replace = new ReflectionMethod(OdpTimeline::class, 'replace');
    $replace->invoke(null, $next, $this->j->odp($this->assessment)->versions()->latest('id')->firstOrFail(), [
        ['ref' => '9.1', 'priority' => 'NEW', 'task' => 'A new task', 'deadline_text' => null, 'due_on' => null, 'date_guessed' => false, 'responsible' => null, 'done' => false],
    ]);
    expect($next->odpTasks()->where('source', OdpTask::CARRIED)->count())->toBe(2);
});

it('shows the review and the carry-over on the follow-up page', function () {
    $page = route('follow-up.show', $this->assessment);
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Annual review')->assertSee('Save the review')
        ->assertSee('can be carried into it from here');

    app(RecordOdpReview::class)->handle($this->assessment, $this->j->assessor, 'The board was trained.', 'No donor yet.');
    $next = Journey::open($this->j->assessor, $this->j->zambia, 'Mar 2028', now());
    $this->actingAs($this->j->assessor)->get($page)->assertSee('Carry unfinished tasks into the Mar 2028 assessment');
    $this->actingAs($this->j->assessor)->post(route('follow-up.carry', $this->assessment), ['tasks' => [$this->open->id]])->assertSessionHasNoErrors();

    $this->actingAs($this->ngs)->get($page)->assertOk()->assertSee('The board was trained.')->assertSee('Carried into Mar 2028')->assertDontSee('Save the review');
});

it('reminds the assessors in the digest to write the review as the next assessment nears', function () {
    $due = MovementStatus::query()->findOrFail($this->j->zambia->id)->next_assessment_on;
    $this->travelTo($due->copy()->subDays(60));

    expect(app(FollowUpDigest::class)->for($this->j->assessor)[0]['review_due'])->toBeTrue()
        ->and(app(FollowUpDigest::class)->for($this->ngs)[0]['review_due'])->toBeFalse();

    app(RecordOdpReview::class)->handle($this->assessment, $this->j->assessor, 'Done.', 'Not done.');
    expect(app(FollowUpDigest::class)->for($this->j->assessor)[0]['review_due'])->toBeFalse();
});
