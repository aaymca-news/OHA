<?php

use App\Actions\Oha\CloseFollowUp;
use App\Actions\Oha\DropTask;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\ReportTaskProgress;
use App\Enums\Stage;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Evidence;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Queries\AllianceInsights;
use App\Queries\FollowUpDigest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

/*
 * Stage 2, the follow-up cycle to its end: evidence added right on a task (it shows in
 * Resources too), dropping an unfinished task with a reason, and closing the cycle once
 * its meetings, its overall review and every task are settled; then it is read-only.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->assessment = $this->j->assessmentAt('odp_signed');
    $this->task = OdpTask::query()->create([
        'assessment_id' => $this->assessment->id, 'source' => OdpTask::TYPED, 'ref' => '1.1',
        'priority' => 'FINANCIAL STABILITY', 'task' => 'Approve a resource mobilisation plan', 'due_on' => now()->addMonths(2)->toDateString(),
    ]);
});

/** Records every follow-up meeting of the year, and the overall review. */
function holdTheYear(object $test): void
{
    foreach ([1, 2, 3] as $n) {
        app(RecordFollowUp::class)->handle($test->assessment, $test->j->assessor, $n, today()->toDateString(), 'Mwila Banda', "Meeting {$n}: tasks reviewed.");
    }
    app(RecordOdpReview::class)->handle($test->assessment, $test->j->assessor, 'The board was trained.', 'The fund was not raised: no donor yet.');
}

it('adds evidence right on a task, as a link or a file, and it shows in Resources backing that task', function () {
    Storage::fake((string) config('oha.disk'));

    $this->actingAs($this->ngs)->post(route('follow-up.evidence', $this->task), ['kind' => 'link', 'url' => 'https://example.org/board-minutes', 'title' => 'Board minutes, June'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $link = Evidence::query()->where('title', 'Board minutes, June')->firstOrFail();
    expect($this->task->refresh()->evidence_id)->toBe($link->id)
        ->and(DB::table('evidence_odp_tasks')->where('evidence_id', $link->id)->value('task'))->toBe('1.1');

    // In Resources, the movement's evidence library, at once.
    $this->actingAs($this->ngs)->get(route('evidence.index', $this->j->zambia))->assertOk()->assertSee('Board minutes, June');

    // A file, by the assessor.
    $this->actingAs($this->j->assessor)->post(route('follow-up.evidence', $this->task), ['kind' => 'file', 'file' => UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf')])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Evidence::query()->where('assessment_id', $this->assessment->id)->count())->toBe(2)
        ->and($this->task->refresh()->evidence->title)->toBe('Approve a resource mobilisation plan');

    // Not the Board Chairperson.
    $this->actingAs($this->j->chair)->post(route('follow-up.evidence', $this->task), ['kind' => 'link', 'url' => 'https://example.org/x'])
        ->assertSessionHasErrors('action');
});

it('drops an unfinished task with a reason, and brings it back; only an assessor', function () {
    expect(fn () => app(DropTask::class)->handle($this->task, $this->j->assessor, null))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(DropTask::class)->handle($this->task, $this->ngs, 'Not needed'))->toThrow(WorkflowRuleBroken::class);

    $this->actingAs($this->j->assessor)->post(route('follow-up.drop', $this->task), ['reason' => 'Replaced by the new fundraising plan.'])->assertSessionHasNoErrors();
    $task = $this->task->refresh();
    expect($task->isDropped())->toBeTrue()->and($task->isSettled())->toBeTrue()
        ->and($task->dropped_reason)->toBe('Replaced by the new fundraising plan.')
        ->and($task->followUpStatus()['label'])->toBe('Dropped');

    $this->actingAs($this->j->assessor)->post(route('follow-up.drop', $this->task), ['undo' => '1'])->assertSessionHasNoErrors();
    expect($this->task->refresh()->isDropped())->toBeFalse();
});

it('closes the cycle once the overall review is written; then it is a read-only record', function () {
    // Nothing written yet: the overall review is what it takes.
    expect(CloseFollowUp::missing($this->assessment))->toBe(['the overall review is not written']);
    expect(fn () => app(CloseFollowUp::class)->handle($this->assessment, $this->j->assessor))->toThrow(WorkflowRuleBroken::class);
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('Close this cycle')->assertSee('write the overall review first');

    holdTheYear($this);
    app(DropTask::class)->handle($this->task, $this->j->assessor, 'No longer relevant.');
    expect(CloseFollowUp::missing($this->assessment))->toBe([])
        ->and(CloseFollowUp::leftOpen($this->assessment))->toBe([]);

    $this->actingAs($this->j->assessor)->post(route('follow-up.close', $this->assessment))->assertRedirect(route('follow-up.show', $this->assessment));
    $assessment = $this->assessment->refresh();
    expect($assessment->isFollowUpClosed())->toBeTrue()
        ->and($assessment->follow_up_closed_by)->toBe($this->j->assessor->id)
        ->and(Stage::of($assessment->load('workItem')))->toBe(Stage::Closed)
        ->and(Stage::Closed->label())->toBe('Cycle complete · ready for the next assessment');
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'ODP cycle complete'));

    // Read-only from now on.
    expect(fn () => app(ReportTaskProgress::class)->handle($this->task->refresh(), $this->ngs, TaskProgress::InProgress))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(RecordFollowUp::class)->handle($assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Again.'))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(CloseFollowUp::class)->handle($assessment, $this->j->assessor))->toThrow(WorkflowRuleBroken::class);
    $this->actingAs($this->ngs)->get(route('follow-up.show', $assessment))->assertOk()
        ->assertSee('Cycle complete')->assertDontSee('Where it stands')->assertDontSee('Record this meeting');
});

it('closes a cycle after one meeting, with a task still open: on the record, and still carried into the next assessment', function () {
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), 'Mwila Banda', 'Tasks reviewed.');
    app(RecordOdpReview::class)->handle($this->assessment, $this->j->assessor, 'The board was trained.', 'The fund was not raised: no donor yet.');
    expect(CloseFollowUp::missing($this->assessment))->toBe([])
        ->and(CloseFollowUp::leftOpen($this->assessment))->toBe(['1 of 3 follow-up meetings held', '1 task not done']);

    // The page says what stays open, before closing.
    $this->actingAs($this->j->assessor)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee('It closes with 1 of 3 follow-up meetings held and 1 task not done', false);

    app(CloseFollowUp::class)->handle($this->assessment, $this->j->assessor);
    expect($this->assessment->refresh()->isFollowUpClosed())->toBeTrue();
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->body, 'with 1 of 3 follow-up meetings held and 1 task not done'));
    $this->actingAs($this->ngs)->get(route('follow-up.show', $this->assessment))->assertOk()->assertSee('Not held');

    // The open task can still go into the next assessment, once it is opened.
    $next = Journey::open($this->j->assessor, $this->j->zambia, 'Mar 2028', now());
    $this->actingAs($this->j->assessor)->post(route('follow-up.carry', $this->assessment), ['tasks' => [$this->task->id]])->assertSessionHasNoErrors();
    expect($this->task->refresh()->carried_to_assessment_id)->toBe($next->id);
});

it('lets the movement’s assessors and the Administrators close the cycle, not the movement', function () {
    expect(Gate::forUser($this->j->assessor)->allows('closeFollowUp', $this->assessment))->toBeTrue()
        ->and(Gate::forUser($this->j->admin)->allows('closeFollowUp', $this->assessment))->toBeTrue()
        ->and(Gate::forUser($this->j->superAdmin)->allows('closeFollowUp', $this->assessment))->toBeTrue()
        ->and(Gate::forUser($this->ngs)->allows('closeFollowUp', $this->assessment))->toBeFalse()
        ->and(Gate::forUser($this->j->chair)->allows('closeFollowUp', $this->assessment))->toBeFalse();

    holdTheYear($this);
    app(DropTask::class)->handle($this->task, $this->j->assessor, 'No longer relevant.');
    app(CloseFollowUp::class)->handle($this->assessment, $this->j->admin);
    expect($this->assessment->refresh()->follow_up_closed_by)->toBe($this->j->admin->id);
});

it('counts a closed cycle in the alliance pipeline, and leaves it out of the weekly digest', function () {
    $this->task->update(['due_on' => now()->subWeek()->toDateString()]);
    expect(app(FollowUpDigest::class)->for($this->ngs))->not->toBe([]);

    holdTheYear($this);
    app(DropTask::class)->handle($this->task, $this->j->assessor, 'No longer relevant.');
    app(CloseFollowUp::class)->handle($this->assessment, $this->j->assessor);

    expect(app(AllianceInsights::class)->pipeline()['closed'])->toBe(1)
        ->and(app(FollowUpDigest::class)->for($this->ngs))->toBe([]);
});

it('lets the Super Administrator write the overall review and close the cycle from the page, without being an assessor', function () {
    expect($this->j->superAdmin->canAssess($this->j->zambia))->toBeFalse();
    $this->actingAs($this->j->superAdmin)->get(route('follow-up.show', $this->assessment))->assertOk()->assertSee('Save the review');
    $this->actingAs($this->j->superAdmin)->post(route('follow-up.review', $this->assessment), ['achieved' => 'The board was trained.', 'not_achieved' => 'The fund was not raised.'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->j->superAdmin)->get(route('follow-up.show', $this->assessment))->assertOk()
        ->assertSee(route('follow-up.close', $this->assessment));
    $this->actingAs($this->j->superAdmin)->post(route('follow-up.close', $this->assessment))->assertRedirect(route('follow-up.show', $this->assessment));
    expect($this->assessment->refresh()->follow_up_closed_by)->toBe($this->j->superAdmin->id);
});
