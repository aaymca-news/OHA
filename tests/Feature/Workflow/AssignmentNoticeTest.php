<?php

use App\Actions\Oha\OpenAssessment;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\InvitationNotice;
use App\Notifications\WorkflowNotice;
use App\Queries\MyWork;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * When an Administrator assigns a movement on the Assessments page, the people are told,
 * it is in the audit trail, and the movement waits in their My Work until its
 * assessment is opened. Users & Roles only shows the assignments.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->staff = User::factory()->create(['name' => 'Aisha Kamara', 'email' => 'aisha@africaymca.org']);
});

it('tells the people when they are assigned on the Assessments page, and when they are replaced', function () {
    $second = User::factory()->create(['name' => 'Kofi Mensah']);
    $this->actingAs($this->j->admin)->from(route('assessments.index'))
        ->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->staff->id, $second->id]])
        ->assertRedirect(route('assessments.index').'#assessors-ghana');

    foreach ([$this->staff, $second] as $person) {
        Notification::assertSentTo($person, WorkflowNotice::class, fn ($n) => $n->subject === 'You are assigned to assess Ghana YMCA'
            && in_array('mail', $n->via($person), true) && str_contains($n->body, 'My Work'));
    }
    expect(AuditEvent::query()->where('action', 'movement.assessors_changed')->where('subject_id', $this->j->ghana->id)->value('payload')['added_names'])->toBe(['Aisha Kamara', 'Kofi Mensah']);

    // Kofi is replaced by another assessor; Aisha stays.
    $third = User::factory()->create(['name' => 'Ama Owusu']);
    $this->actingAs($this->j->admin)->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->staff->id, $third->id]]);

    Notification::assertSentTo($second, WorkflowNotice::class, fn ($n) => $n->subject === 'You no longer assess Ghana YMCA');
    Notification::assertSentTo($third, WorkflowNotice::class, fn ($n) => $n->subject === 'You are assigned to assess Ghana YMCA');
    expect($this->j->ghana->assessors()->orderBy('name')->pluck('name')->all())->toBe(['Aisha Kamara', 'Ama Owusu']);
});

it('starts a new person with no assessment, whatever the invitation form sends', function () {
    $this->actingAs($this->j->admin)->post(route('admin.users.store'), [
        'name' => 'Kofi Mensah', 'email' => 'kofi@africaymca.org', 'role' => 'staff', 'movement_ids' => [$this->j->ghana->id],
    ])->assertRedirect();
    $kofi = User::query()->where('email', 'kofi@africaymca.org')->firstOrFail();

    Notification::assertSentTo($kofi, InvitationNotice::class);
    expect($kofi->assignedMovements()->count())->toBe(0);
});

it('puts an assigned movement with no assessment under way in My Work, until its assessment is opened', function () {
    $this->staff->assignedMovements()->attach($this->j->ghana);
    $groups = fn () => collect(app(MyWork::class)->for($this->staff))->keyBy('key');

    expect($groups()['start']['items'][0]['movement']->slug)->toBe('ghana')
        ->and($groups()['start']['items'][0]['actionable'])->toBeTrue()
        ->and(app(MyWork::class)->actionableCount($this->staff))->toBe(1);

    $this->actingAs($this->staff)->get(route('my-work'))->assertOk()
        ->assertSee('To be started')->assertSee('Ghana YMCA')->assertSee('not assessed yet')->assertSee('Start the assessment');
    // The dashboard is analytics only: the work is in My Work.
    $this->actingAs($this->staff)->get(route('dashboard'))->assertOk()->assertDontSee('Start the assessment');

    app(OpenAssessment::class)->handle($this->staff, $this->j->ghana, 'Oct 2026', Carbon::parse('2026-10-01'));

    // Opened, nothing uploaded yet: still to be started, now with the OHA form to upload.
    expect($groups()['start']['items'])->toHaveCount(1)
        ->and($groups()['start']['items'][0]['opened'] ?? false)->toBeTrue()
        ->and($groups()['start']['items'][0]['actionable'])->toBeTrue()
        ->and($groups()['started']['items'])->toBe([]);
});
