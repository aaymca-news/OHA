<?php

use App\Actions\Oha\AssignAssessors;
use App\Actions\Oha\OpenAssessment;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\SystemHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('lets the Administrators assign staff, and tells them', function () {
    foreach ([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $assigner) {
        app(AssignAssessors::class)->handle($assigner, $this->j->ghana, [$this->j->otherStaff->id]);
    }

    expect($this->j->otherStaff->canAssess($this->j->ghana))->toBeTrue()
        ->and(Notification::sent($this->j->otherStaff, WorkflowNotice::class)->pluck('subject'))->toContain('You are assigned to assess Ghana YMCA');
});

it('lets an Administrator be assigned to assess, like any staff member', function () {
    app(AssignAssessors::class)->handle($this->j->superAdmin, $this->j->ghana, [$this->j->admin->id]);

    expect($this->j->admin->canAssess($this->j->ghana))->toBeTrue();
});

it('never lets staff give themselves work, nor the Board Chairperson assign it', function () {
    foreach ([$this->j->otherStaff, $this->j->chair] as $notAssigner) {
        expect(fn () => app(AssignAssessors::class)->handle($notAssigner, $this->j->ghana, [$this->j->otherStaff->id]))
            ->toThrow(WorkflowRuleBroken::class, 'Only the Administrators assign staff');
    }

    expect(fn () => app(OpenAssessment::class)->handle($this->j->otherStaff, $this->j->ghana, 'Feb 2026', Carbon::parse('2026-02-01')))
        ->toThrow(WorkflowRuleBroken::class, 'not assigned');
});

it('assigns only active AAYMCA staff', function () {
    expect(fn () => app(AssignAssessors::class)->handle($this->j->admin, $this->j->ghana, [$this->j->chair->id]))
        ->toThrow(WorkflowRuleBroken::class, 'Only active AAYMCA staff');
});

it('flags a missing Administrator, Super Administrator or Board Chairperson', function () {
    // Every movement but Zambia and Ghana still lacks a Chairperson in this walkthrough.
    expect(SystemHealth::check()['movements_without_chair'])->not->toContain('Zambia YMCA')->toContain('Kenya YMCA');

    $this->j->secondAdmin->update(['active' => false]);
    $this->j->superAdmin->update(['active' => false]);
    $health = SystemHealth::check();

    expect($health['severity'])->toBe('serious')
        ->and(implode(' ', $health['problems']))->toContain('only one Administrator')->toContain('no Super Administrator')->toContain('have no Board Chairperson');

    $this->j->admin->update(['active' => false]);

    expect(SystemHealth::check()['severity'])->toBe('critical')
        ->and(implode(' ', SystemHealth::check()['problems']))->toContain('There is no Administrator. Nothing can be approved.');
});

it('reminds the Administrators, and nobody else', function () {
    $this->artisan('oha:remind-missing-roles')->assertSuccessful();

    foreach ([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $user) {
        Notification::assertSentTo($user, WorkflowNotice::class, fn ($n) => $n->subject === 'Reminder: roles still to be appointed');
    }
    foreach ([$this->j->assessor, $this->j->otherStaff, $this->j->chair] as $user) {
        Notification::assertNotSentTo($user, WorkflowNotice::class);
    }
});

it('sends no reminder once every role is held', function () {
    foreach (Movement::query()->whereNotIn('slug', ['zambia', 'ghana'])->get() as $movement) {
        User::factory()->chair($movement)->create();
    }

    $this->artisan('oha:remind-missing-roles')->expectsOutputToContain('No reminder sent')->assertSuccessful();
    Notification::assertNothingSent();
});
