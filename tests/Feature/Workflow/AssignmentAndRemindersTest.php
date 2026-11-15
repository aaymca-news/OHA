<?php

use App\Actions\Oha\AssignAssessors;
use App\Actions\Oha\OpenAssessment;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\SystemHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

it('flags a missing Administrator, Super Administrator or National General Secretary', function () {
    $this->j->secondAdmin->update(['active' => false]);
    $this->j->superAdmin->update(['active' => false]);
    $health = SystemHealth::check();

    expect($health['severity'])->toBe('serious')
        ->and(implode(' ', $health['problems']))->not->toContain('only one Administrator')->toContain('no Super Administrator')->toContain('have no National General Secretary');

    $this->j->admin->update(['active' => false]);

    expect(SystemHealth::check()['severity'])->toBe('critical')
        ->and(implode(' ', SystemHealth::check()['problems']))->toContain('There is no Administrator. Nothing can be approved.');
});

it('never flags a movement without a Board Chairperson, whose signature is optional (16 Nov 2026)', function () {
    // Every movement but Zambia and Ghana lacks a Chairperson in this walkthrough; each gets its General Secretary.
    foreach (Movement::query()->get() as $movement) {
        User::factory()->generalSecretary($movement)->create();
    }

    expect(SystemHealth::check()['ok'])->toBeTrue()
        ->and(SystemHealth::check())->not->toHaveKey('movements_without_chair');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Roles still to be appointed')->assertDontSee('Movements without a Board Chairperson');
});

it('sends no daily email about roles still to be appointed: the banner is enough', function () {
    expect(Artisan::all())->not->toHaveKey('oha:remind-missing-roles');
    $this->artisan('schedule:list')->doesntExpectOutputToContain('remind-missing-roles')->assertSuccessful();

    // The banner still shows what is missing, without promising a daily reminder.
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Roles still to be appointed')->assertSee('Movements without a National General Secretary')
        ->assertDontSee('reminded daily')->assertDontSee('Movements without a Board Chairperson');
    Notification::assertNothingSent();
});
