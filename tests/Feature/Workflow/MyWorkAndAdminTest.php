<?php

use App\Actions\ChangeUserRole;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use App\Queries\MyWork;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

function groupOf(User $user, string $key): ?array
{
    return collect(app(MyWork::class)->for($user))->firstWhere('key', $key);
}

it('puts a submitted form in every Administrator’s queue, and no staff member’s', function () {
    $this->j->assessmentAt('form_submitted');

    foreach ([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $admin) {
        expect(groupOf($admin, 'approve')['items'])->toHaveCount(1)
            ->and(groupOf($admin, 'approve')['items'][0]['actionable'])->toBeTrue()
            ->and(app(MyWork::class)->actionableCount($admin))->toBe(1);
    }

    expect(groupOf($this->j->otherStaff, 'approve'))->toBeNull()
        ->and(groupOf($this->j->assessor, 'watching')['items'])->toHaveCount(1);
});

it('lets an Administrator approve their own submission, like any other', function () {
    $this->j->admin->assignedMovements()->attach($this->j->zambia);
    $assessment = $this->j->assessmentAt('opened');
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'f.xlsx', $this->j->admin);
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->admin, acknowledgeGaps: true);

    expect(groupOf($this->j->admin, 'approve')['items'][0]['actionable'])->toBeTrue()
        ->and(groupOf($this->j->secondAdmin, 'approve')['items'][0]['actionable'])->toBeTrue();
});

it('lists forms that went forward with gaps, for the movement’s assessors and the Administrators', function () {
    $this->j->assessmentAt('form_submitted');
    $elsewhere = User::factory()->create();
    $elsewhere->assignedMovements()->attach($this->j->ghana);

    expect(groupOf($this->j->assessor, 'gaps')['items'])->toHaveCount(1)
        ->and(groupOf($this->j->secondAdmin, 'gaps')['items'])->toHaveCount(1)
        ->and(groupOf($elsewhere, 'gaps'))->toBeNull();
});

it('gives the Board Chairperson the approved ODP to sign, and nothing else', function () {
    $this->j->assessmentAt('odp_approved');

    $chair = groupOf($this->j->chair, 'sign')['items'];

    expect($chair)->toHaveCount(1)
        ->and($chair[0]['artefact']->kind->value)->toBe('odp')
        ->and($chair[0]['actionable'])->toBeTrue()
        ->and(groupOf($this->j->ghanaChair, 'sign')['items'])->toBe([])
        ->and(groupOf($this->j->assessor, 'sign'))->toBeNull();
});

it('never lets anyone change their own role', function () {
    expect(fn () => app(ChangeUserRole::class)->handle($this->j->superAdmin, $this->j->superAdmin, Role::Staff))
        ->toThrow(WorkflowRuleBroken::class, 'You cannot change your own role')
        ->and(fn () => app(ChangeUserRole::class)->handle($this->j->assessor, $this->j->otherStaff, Role::Staff))
        ->toThrow(WorkflowRuleBroken::class, 'Only the Administrators manage users');
});

it('lets only the Super Administrator give or take away an Administrator role', function () {
    expect(fn () => app(ChangeUserRole::class)->handle($this->j->admin, $this->j->otherStaff, Role::Admin))
        ->toThrow(WorkflowRuleBroken::class, 'Only the Super Administrator gives the Administrator roles')
        ->and(fn () => app(ChangeUserRole::class)->handle($this->j->admin, $this->j->secondAdmin, Role::Staff))
        ->toThrow(WorkflowRuleBroken::class, 'Only the Super Administrator manages an Administrator’s account');

    app(ChangeUserRole::class)->handle($this->j->superAdmin, $this->j->otherStaff, Role::Admin);
    app(ChangeUserRole::class)->handle($this->j->superAdmin, $this->j->secondAdmin, Role::Staff);

    expect($this->j->otherStaff->refresh()->role)->toBe(Role::Admin)
        ->and($this->j->secondAdmin->refresh()->role)->toBe(Role::Staff);
});

it('lets an Administrator make someone Staff or a Board Chairperson', function () {
    $newcomer = User::factory()->create();
    app(ChangeUserRole::class)->handle($this->j->admin, $newcomer, Role::Board, $this->j->ghana->refresh(), confirmReplace: true);

    expect($newcomer->refresh()->role)->toBe(Role::Board)
        ->and($newcomer->movement_id)->toBe($this->j->ghana->id)
        ->and($this->j->ghanaChair->refresh()->active)->toBeFalse();
});

it('replaces a movement’s Board Chairperson only with confirmation', function () {
    $newcomer = User::factory()->create(['name' => 'Samuel Mwangi']);

    expect(fn () => app(ChangeUserRole::class)->handle($this->j->admin, $newcomer, Role::Board, $this->j->zambia))
        ->toThrow(WorkflowRuleBroken::class, 'Naledi Moyo is Zambia YMCA’s Board Chairperson');

    expect($newcomer->refresh()->role)->toBe(Role::Staff)
        ->and($this->j->chair->refresh()->active)->toBeTrue();
});
