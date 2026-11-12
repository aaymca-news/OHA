<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\ReviseOdp;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\TakeOdpFromDrive;
use App\Actions\Oha\UploadOdpVersion;
use App\Enums\DocumentPurpose;
use App\Enums\TaskProgress;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\FollowUpWindow;
use App\Models\OdpTask;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Google\GoogleDrive;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeDrive;
use Tests\Support\Journey;
use Tests\Support\OdpWorkbook;

/*
 * Revising the ODP during the follow-ups (decided 12 Nov 2026): while a follow-up is open,
 * the revised ODP is uploaded (or taken from Google Drive), with no separate approval. Its
 * tasks are matched against the plan in place: a task still there keeps where it stands,
 * a new one is added, one no longer there is removed (kept for the record, out of the plan).
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->drive = new FakeDrive;
    app()->instance(GoogleDrive::class, $this->drive);
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);

    // An ODP workbook from [number, task, responsible] rows.
    $this->book = fn (array $tasks) => OdpWorkbook::make([
        [null, 'ORGANISATIONAL DEVELOPMENT PLAN'],
        [null, 'ORGANISATION: ', 'ZAMBIA YMCA'],
        [null, 'PERIOD OF IMPLEMENTATION:', 'April 2026 - March 2027'],
        [],
        ['№', 'OBJECTIVES', 'EXPECTED RESULTS', 'MEANS OF VERIFICATION', 'TASKS', 'TO COMPLETE THE TASK BY DEADLINE', 'RESPONSIBLE ONES', 'TASK COMPLETED'],
        ['STRATEGIC PRIORITY ONE (1):', null, null, 'FINANCIAL STABILITY'],
        ...array_map(fn (array $t) => [$t[0], 'An objective', 'A result', 'Minutes', $t[1], 'Ongoing', $t[2], 'FALSE'], $tasks),
        [],
        [null, 'Plan created by (names and positions):'],
    ]);

    // Stage 1 with an Excel ODP of three tasks, approved and closed.
    $this->assessment = $this->j->assessmentAt('report_approved');
    app(UploadOdpVersion::class)->handle($this->j->odp($this->assessment), ($this->book)([
        ['1.1', 'Draft the strategy', 'General Secretary'],
        ['1.2', 'Review the accounts', 'Treasurer'],
        ['2.1', 'Train the board', 'Board'],
    ]), 'Zambia ODP.xlsx', $this->j->assessor, null, Journey::DRIVE_URL);
    app(SubmitForApproval::class)->handle($this->j->odp($this->assessment), $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->odp($this->assessment), $this->j->admin);
    $this->j->close($this->assessment);
    $this->task = fn (string $ref) => OdpTask::query()->withoutGlobalScope(OdpTask::IN_PLAN)
        ->where('assessment_id', $this->assessment->id)->where('ref', $ref)->sole();
    $this->revise = fn (array $tasks, ?User $by = null) => app(ReviseOdp::class)
        ->upload($this->j->odp($this->assessment), ($this->book)($tasks), 'Zambia ODP revised.xlsx', $by ?? $this->j->assessor, 'After the board meeting');
});

it('matches a revised ODP’s tasks against the plan in place, keeping where each stands', function () {
    $first = ($this->task)('1.1');
    $this->j->openFollowUp($this->assessment);
    app(RecordFollowUp::class)->handle($this->assessment, $this->j->assessor, 1, today()->toDateString(), null, 'Tasks reviewed.',
        statuses: [$first->id => TaskProgress::InProgress->value]);
    Notification::fake();

    $revision = ($this->revise)([
        ['1.1', 'Draft the strategy', 'Finance Committee'],
        ['1.2', 'Review the accounts every quarter', 'Treasurer'],
        ['3.1', 'Publish the annual report', 'Communications'],
    ]);

    expect($revision->purpose)->toBe(DocumentPurpose::Revision)
        ->and($revision->follow_up_number)->toBe(1)
        // The same task, still where it stood, with its new person responsible.
        ->and(($this->task)('1.1')->id)->toBe($first->id)
        ->and(($this->task)('1.1')->progress)->toBe(TaskProgress::InProgress)
        ->and(($this->task)('1.1')->responsible)->toBe('Finance Committee')
        // Reworded, matched by its number.
        ->and(($this->task)('1.2')->task)->toBe('Review the accounts every quarter')
        // Removed: kept for the record, out of the plan.
        ->and(($this->task)('2.1')->isRemoved())->toBeTrue()
        ->and($this->assessment->odpTasks()->pluck('ref')->all())->toBe(['1.1', '1.2', '3.1'])
        ->and(($this->task)('3.1')->progress)->toBe(TaskProgress::NotStarted)
        ->and($revision->changes)->toMatchArray([
            'added' => ['3.1 · Publish the annual report'],
            'removed' => ['2.1 · Train the board'],
            'reworded' => ['1.2 · Review the accounts every quarter (was: Review the accounts)'],
            'responsible' => ['1.1 · Draft the strategy: Finance Committee (was: General Secretary)'],
        ])
        // Stage 1's approved ODP stays as it was: no approval cycle restarts.
        ->and($this->j->odp($this->assessment)->isApproved())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'odp.revised')->count())->toBe(1);
    Notification::assertSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => $n->subject === 'ODP revised: Zambia YMCA'
        && str_contains($n->body, '1 task added'));

    // A removed task that comes back is restored, the same task.
    $removed = ($this->task)('2.1');
    ($this->revise)([['1.1', 'Draft the strategy', 'Finance Committee'], ['2.1', 'Train the board', 'Board']]);
    expect(($this->task)('2.1')->id)->toBe($removed->id)->and(($this->task)('2.1')->isRemoved())->toBeFalse();
});

it('revises only while a follow-up is open, by the assessors or an Administrator', function () {
    $book = ($this->book)([['1.1', 'Draft the strategy', 'General Secretary']]);
    expect(fn () => app(ReviseOdp::class)->upload($this->j->odp($this->assessment), $book, 'ODP.xlsx', $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'Open the follow-up first');

    $this->j->openFollowUp($this->assessment);
    $odp = $this->j->odp($this->assessment);
    expect($this->j->assessor->can('revise', $odp))->toBeTrue()
        ->and($this->j->secondAdmin->can('revise', $odp))->toBeTrue()
        ->and($this->ngs->can('revise', $odp))->toBeFalse()
        ->and($this->j->chair->can('revise', $odp))->toBeFalse()
        ->and($this->j->otherStaff->can('revise', $odp))->toBeFalse();
});

it('takes a Google Drive change as a revision while a follow-up is open, and a change made meanwhile when the next opens', function () {
    $take = fn () => app(TakeOdpFromDrive::class)->handle($this->j->odp($this->assessment));

    // No follow-up open: noted only.
    $this->drive->edit('changed before the follow-up', $this->j->assessor->email);
    expect($take()['version']->purpose)->toBe(DocumentPurpose::AfterSigning);

    // Opening the follow-up takes it in, as a revision of follow-up 1.
    app(FollowUpWindows::class)->open($this->assessment, $this->j->assessor);
    $adopted = Document::query()->where('purpose', DocumentPurpose::Revision)->sole();
    expect($adopted->follow_up_number)->toBe(1)->and($adopted->fromDrive())->toBeTrue();

    // While it is open, a change is a revision at once.
    $this->drive->edit('changed during the follow-up', $this->j->assessor->email);
    $result = $take();
    expect($result['outcome'])->toBe(TakeOdpFromDrive::SAVED)
        ->and($result['version']->purpose)->toBe(DocumentPurpose::Revision)
        ->and($take()['outcome'])->toBe(TakeOdpFromDrive::UNCHANGED);
});

it('keeps the ODP version in force with each follow-up closed, and shows the revisions on the follow-up page', function () {
    $this->j->openFollowUp($this->assessment);
    $revision = ($this->revise)([['1.1', 'Draft the strategy', 'General Secretary'], ['1.2', 'Review the accounts', 'Treasurer']]);
    $this->j->holdFollowUp($this->assessment, 1);

    expect(FollowUpWindow::query()->where('assessment_id', $this->assessment->id)->where('number', 1)->sole()->odp_document_id)->toBe($revision->id);

    $page = route('follow-up.show', $this->assessment);
    $this->actingAs($this->ngs)->get($page)->assertOk()
        ->assertSee('The ODP during the follow-ups')->assertSee('Removed from the plan (kept for the record)')->assertSee('2.1 · Train the board')
        ->assertDontSee('Save the revised ODP');
    // Follow-up 1 closed, follow-up 2 not open: no upload until it is.
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertDontSee('Save the revised ODP')
        ->assertSee('Changes made in Google Drive meanwhile are noted');
    $this->j->openFollowUp($this->assessment);
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Save the revised ODP');
});
