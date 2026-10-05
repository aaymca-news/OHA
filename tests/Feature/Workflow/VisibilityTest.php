<?php

use App\Actions\Oha\ApproveArtefact;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Who sees what. Work in progress: the movement's assessors and the Administrators.
 * Once approved: every AAYMCA staff member. The Board Chairperson: the approved
 * report and ODP of their own movement, never the raw form.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->can = fn ($user, $ability, $subject) => Gate::forUser($user)->allows($ability, $subject);
});

it('shows work in progress only to those working on it or overseeing it', function () {
    $form = $this->j->form($this->j->assessmentAt('form_submitted'));

    foreach ([$this->j->assessor, $this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $user) {
        expect(($this->can)($user, 'view', $form))->toBeTrue("{$user->name} should see work in progress");
    }

    expect(($this->can)($this->j->otherStaff, 'view', $form))->toBeFalse()
        ->and(($this->can)($this->j->chair, 'view', $form))->toBeFalse();
});

it('shows an approved document to every AAYMCA staff member', function () {
    $form = $this->j->form($this->j->assessmentAt('form_approved'));

    expect(($this->can)($this->j->otherStaff, 'view', $form))->toBeTrue()
        // The form never reaches the Board Chairperson.
        ->and(($this->can)($this->j->chair, 'view', $form))->toBeFalse();
});

it('shows the Board Chairperson the report and ODP as each is approved, for their own movement only', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');

    expect(($this->can)($this->j->chair, 'view', $this->j->report($assessment)))->toBeTrue()
        ->and(($this->can)($this->j->chair, 'view', $this->j->odp($assessment)))->toBeFalse()
        ->and(($this->can)($this->j->chair, 'view', $this->j->form($assessment)))->toBeFalse()
        ->and(($this->can)($this->j->chair, 'view', $assessment))->toBeTrue()
        ->and(($this->can)($this->j->ghanaChair, 'view', $this->j->report($assessment)))->toBeFalse()
        ->and(($this->can)($this->j->ghanaChair, 'view', $assessment))->toBeFalse();

    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);

    expect(($this->can)($this->j->chair, 'view', $this->j->odp($assessment)))->toBeTrue();
});

it('shows the audit trail to the Administrators and the movement’s assessors only', function () {
    $assessment = $this->j->assessmentAt('form_approved');

    foreach ([$this->j->assessor, $this->j->admin, $this->j->secondAdmin, $this->j->superAdmin] as $user) {
        expect(($this->can)($user, 'viewAudit', $assessment))->toBeTrue("{$user->name} should see the audit trail");
    }
    foreach ([$this->j->otherStaff, $this->j->chair] as $user) {
        expect(($this->can)($user, 'viewAudit', $assessment))->toBeFalse();
    }
});
