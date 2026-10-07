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
 * However an Administrator assigns a movement (its own page, or a person's page in
 * Users & Roles), the person is told, it is in the audit trail, and the movement waits
 * in their My Work until its assessment is opened.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->staff = User::factory()->create(['name' => 'Aisha Kamara', 'email' => 'aisha@africaymca.org']);
});

it('tells the person when they are assigned from Users & Roles, and when they are taken off', function () {
    $this->actingAs($this->j->admin)->put(route('admin.users.update', $this->staff), [
        'name' => 'Aisha Kamara', 'email' => 'aisha@africaymca.org', 'movement_ids' => [$this->j->ghana->id],
    ])->assertRedirect();

    Notification::assertSentTo($this->staff, WorkflowNotice::class, fn ($n) => $n->subject === 'You are assigned to assess Ghana YMCA'
        && in_array('mail', $n->via($this->staff), true) && str_contains($n->body, 'My Work'));
    expect(AuditEvent::query()->where('action', 'movement.assessors_changed')->where('subject_id', $this->j->ghana->id)->value('payload')['added_names'])->toBe(['Aisha Kamara']);

    $this->actingAs($this->j->admin)->put(route('admin.users.update', $this->staff), [
        'name' => 'Aisha Kamara', 'email' => 'aisha@africaymca.org', 'movement_ids' => [],
    ]);

    Notification::assertSentTo($this->staff, WorkflowNotice::class, fn ($n) => $n->subject === 'You no longer assess Ghana YMCA');
});

it('tells the person when they are assigned from the movement’s page', function () {
    $this->actingAs($this->j->admin)->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->staff->id]])->assertRedirect();

    Notification::assertSentTo($this->staff, WorkflowNotice::class, fn ($n) => $n->subject === 'You are assigned to assess Ghana YMCA');
});

it('names the movements in a new person’s invitation instead of a separate notice', function () {
    $this->actingAs($this->j->admin)->post(route('admin.users.store'), [
        'name' => 'Kofi Mensah', 'email' => 'kofi@africaymca.org', 'role' => 'staff', 'movement_ids' => [$this->j->ghana->id, $this->j->zambia->id],
    ])->assertRedirect();
    $kofi = User::query()->where('email', 'kofi@africaymca.org')->firstOrFail();

    Notification::assertSentTo($kofi, InvitationNotice::class, fn (InvitationNotice $n) => str_contains(implode(' ', $n->toMail($kofi)->introLines), 'You are assigned to assess: Ghana YMCA and Zambia YMCA'));
    Notification::assertNotSentTo($kofi, WorkflowNotice::class);
    expect(AuditEvent::query()->where('action', 'movement.assessors_changed')->count())->toBe(2);
});

it('puts an assigned movement with no assessment under way in My Work, until its assessment is opened', function () {
    $this->staff->assignedMovements()->attach($this->j->ghana);
    $groups = fn () => collect(app(MyWork::class)->for($this->staff))->keyBy('key');

    expect($groups()['start']['items'][0]['movement']->slug)->toBe('ghana')
        ->and($groups()['start']['items'][0]['actionable'])->toBeTrue()
        ->and(app(MyWork::class)->actionableCount($this->staff))->toBe(1);

    $this->actingAs($this->staff)->get(route('my-work'))->assertOk()
        ->assertSee('Movements to start')->assertSee('Ghana YMCA')->assertSee('not assessed yet')->assertSee('Start the assessment');
    $this->actingAs($this->staff)->get(route('dashboard'))->assertOk()->assertSee('Start the assessment');

    app(OpenAssessment::class)->handle($this->staff, $this->j->ghana, 'Oct 2026', Carbon::parse('2026-10-01'));

    expect($groups()->has('start'))->toBeFalse()
        ->and(collect($groups()['mine']['items'])->pluck('artefact.assessment.movement.slug')->all())->toBe(['ghana']);
});
