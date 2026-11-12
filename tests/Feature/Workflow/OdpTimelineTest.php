<?php

use App\Actions\Oha\UploadOdpVersion;
use App\Models\OdpTask;
use App\Oha\OdpTaskReader;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\OdpWorkbook;

/*
 * Timelines follows each ODP's tasks: read from the ODP workbook as it is saved,
 * typed in when it cannot be read, and corrected by the movement's assessors (or an
 * Administrator). Staff see and change only the movements they assess.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->assessment = $this->j->assessmentAt('report_uploaded');
    $this->workbook = fn (string $first = 'By May 2026') => OdpWorkbook::make([
        [null, 'ORGANISATIONAL DEVELOPMENT PLAN'],
        [null, 'ORGANISATION: ', 'ZAMBIA YMCA'],
        [null, 'PERIOD OF IMPLEMENTATION:', 'April 2026 - March 2027'],
        [],
        ['№', 'OBJECTIVES', 'EXPECTED RESULTS', 'MEANS OF VERIFICATION', 'TASKS', 'TO COMPLETE THE TASK BY DEADLINE', 'RESPONSIBLE ONES', 'TASK COMPLETED'],
        ['STRATEGIC PRIORITY ONE (1):', null, null, 'FINANCIAL STABILITY'],
        ['1.1', 'A fundraising strategy', 'Adopted', 'Minutes', 'Draft the strategy', $first, 'General Secretary', 'FALSE'],
        [null, null, null, null, 'Adopt it at the Board', 'End of January', 'Board', 'TRUE'],
        ['1.2', 'Reviews', 'Held', 'Reports', 'Review the accounts', 'Ongoing', 'Treasurer', 'FALSE'],
        [],
        [null, 'Plan created by (names and positions):'],
    ]);
    $this->upload = fn (string $path) => app(UploadOdpVersion::class)
        ->handle($this->j->odp($this->assessment), $path, 'Zambia ODP.xlsx', $this->j->assessor, null, 'https://docs.google.com/spreadsheets/d/1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr/edit');
    $this->tasks = fn () => OdpTask::query()->where('assessment_id', $this->assessment->id)->orderBy('sort_order')->get();
});

it('reads a deadline however the ODP writes it', function (string $text, ?string $date, bool $guessed) {
    expect(OdpTaskReader::deadline($text, 2026, 4))->toBe([$date, $guessed]);
})->with([
    'month and year' => ['By May 2026', '2026-05-31', false],
    'short year' => ['Dec-25', '2025-12-31', false],
    'a day' => ['April 13/2026', '2026-04-13', false],
    'several: the last' => ['1. Aprl -May 2026 2. Board Approval By june 2026', '2026-06-30', false],
    'a quarter with its year' => ['End of first quater 2027', '2027-03-31', false],
    'a year alone' => ['End of 2026', '2026-12-31', false],
    'no year: the period’s' => ['By end of June', '2026-06-30', true],
    'no year, before the period starts' => ['End of January', '2027-01-31', true],
    'a quarter, no year' => ['2nd quater', '2026-06-30', true],
    'no date' => ['Ongoing', null, false],
    'a word that is not a month' => ['Separate the market plans', null, false],
]);

it('reads the ODP’s tasks as it is saved: deadlines, who is responsible, and what is done', function () {
    ($this->upload)(($this->workbook)());

    $tasks = ($this->tasks)();
    expect($tasks)->toHaveCount(3)
        ->and($tasks->pluck('ref')->all())->toBe(['1.1', '1.1', '1.2'])
        ->and($tasks->pluck('priority')->unique()->all())->toBe(['FINANCIAL STABILITY'])
        ->and($tasks[0]->due_on->toDateString())->toBe('2026-05-31')
        ->and($tasks[0]->responsible)->toBe('General Secretary')
        ->and($tasks[1]->due_on->toDateString())->toBe('2027-01-31')
        ->and($tasks[1]->date_guessed)->toBeTrue()
        ->and($tasks[1]->done)->toBeTrue()
        ->and($tasks[2]->due_on)->toBeNull()
        ->and($tasks[2]->deadline_text)->toBe('Ongoing');
});

it('lets the assessors correct a task, and keeps the correction when a newer ODP is read', function () {
    ($this->upload)(($this->workbook)());
    $first = ($this->tasks)()->first();

    $this->actingAs($this->j->assessor)->put(route('timeline.tasks.update', $first), [
        'task' => 'Draft the strategy', 'responsible' => 'The Finance Committee',
    ])->assertSessionHasNoErrors();

    ($this->upload)(($this->workbook)('By July 2026'));

    $again = ($this->tasks)()->first();
    expect($again->id)->not->toBe($first->id)
        ->and($again->responsible)->toBe('The Finance Committee')
        ->and($again->edited_by)->toBe($this->j->assessor->id)
        ->and($this->assessment->auditEvents()->where('action', 'timeline.updated')->exists())->toBeTrue();
});

it('says why an ODP that is not a workbook cannot be read, and takes its tasks typed in', function () {
    $this->j->uploadOdp($this->assessment, 'Zambia ODP as a PDF');

    $this->actingAs($this->j->assessor)->get(route('timelines', ['tab' => 'odp', 'movement' => 'zambia']))->assertOk()
        ->assertSee('cannot be read automatically')->assertSee('Add a task');

    $this->actingAs($this->j->assessor)->post(route('timeline.tasks.store', $this->assessment), [
        'ref' => '2.1', 'task' => 'Hold a Board retreat', 'responsible' => 'Board',
    ])->assertSessionHasNoErrors();

    expect(($this->tasks)()->sole()->only(['ref', 'task', 'source']))->toBe(['ref' => '2.1', 'task' => 'Hold a Board retreat', 'source' => 'typed']);
    $this->actingAs($this->j->assessor)->get(route('timelines', ['tab' => 'odp', 'movement' => 'zambia']))->assertSee('Hold a Board retreat')->assertSee('Typed in');
});

it('shows staff only the movements they assess, and lets them change only those', function () {
    ($this->upload)(($this->workbook)());
    $task = ($this->tasks)()->first();

    $this->actingAs($this->j->otherStaff)->get(route('timelines', ['tab' => 'odp']))->assertOk()
        ->assertSee('None of the movements you assess has an ODP yet')->assertDontSee('Draft the strategy');
    $this->actingAs($this->j->otherStaff)->put(route('timeline.tasks.update', $task), ['task' => 'Changed', 'done' => '1'])
        ->assertSessionHasErrors('action');
    expect($task->refresh()->task)->toBe('Draft the strategy');

    $this->actingAs($this->j->assessor)->get(route('timelines', ['tab' => 'odp']))->assertOk()->assertSee('Zambia YMCA')->assertSee('Draft the strategy');
    $this->actingAs($this->j->admin)->get(route('timelines', ['tab' => 'odp']))->assertOk()->assertSee('Draft the strategy')->assertSee('Correct');
});
