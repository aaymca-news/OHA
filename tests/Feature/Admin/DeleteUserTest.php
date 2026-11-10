<?php

use App\Actions\Users\DeleteUser;
use App\Actions\Users\InviteUser;
use App\Actions\Users\SetUserActive;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Deleting an account, once deactivated: it leaves Users & Roles for good and its email
 * is freed, but the person's name stays in the audit trail, on what they did.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    // Some history by the assessor: they opened an assessment and uploaded a form.
    $this->j->assessmentAt('form_uploaded');
});

it('deletes a deactivated account: gone from Users & Roles, email freed, the audit trail still naming them', function () {
    $tendai = $this->j->assessor;
    $email = $tendai->email;
    $history = AuditEvent::query()->where('actor_id', $tendai->id)->count();
    expect($history)->toBeGreaterThan(0);

    // Not while the account is active.
    expect(fn () => app(DeleteUser::class)->handle($this->j->admin, $tendai))->toThrow(WorkflowRuleBroken::class, 'Deactivate');

    app(SetUserActive::class)->handle($this->j->admin, $tendai, false);
    $this->actingAs($this->j->admin)->get(route('admin.users.edit', $tendai))->assertOk()->assertSee('Delete the account');
    $this->actingAs($this->j->admin)->delete(route('admin.users.destroy', $tendai))->assertRedirect(route('admin.users.index'));

    $tendai->refresh();
    expect($tendai->isDeleted())->toBeTrue()
        ->and($tendai->name)->toBe('Tendai Moyo')
        ->and($tendai->email)->not->toBe($email)
        ->and($tendai->assignedMovements()->count())->toBe(0)
        // The history stays, naming them; the deletion is in it too.
        ->and(AuditEvent::query()->where('actor_id', $tendai->id)->count())->toBe($history)
        ->and(AuditEvent::query()->where('actor_id', $tendai->id)->first()->actor->name)->toBe('Tendai Moyo')
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('subject_id', $tendai->id)->first()->payload['email'] ?? null)->toBe($email);

    // Gone from Users & Roles, and nothing more can be done to it.
    // (The page's message after deleting names them; the list itself no longer does.)
    $this->actingAs($this->j->admin)->get(route('admin.users.index'))->assertOk()
        ->assertSee('account is deleted')->assertDontSee(route('admin.users.edit', $tendai));
    $this->actingAs($this->j->admin)->get(route('admin.users.edit', $tendai))->assertOk()->assertSee('This account was deleted')->assertDontSee('Reactivate');

    // The email is free: the person can be invited again, as a new account.
    $new = app(InviteUser::class)->handle($this->j->admin, 'Tendai Moyo', $email, Role::Staff);
    expect($new->id)->not->toBe($tendai->id)->and($new->email)->toBe($email);

    // And the deleted account cannot sign in.
    auth()->logout();
    $this->post(route('login'), ['email' => $email, 'password' => 'password'])->assertSessionHasErrors();
});

it('lets nobody delete their own account, and only the Super Administrator an Administrator’s', function () {
    $admin = $this->j->secondAdmin;
    app(SetUserActive::class)->handle($this->j->superAdmin, $admin, false);

    expect(fn () => app(DeleteUser::class)->handle($this->j->admin, $admin))->toThrow(WorkflowRuleBroken::class)
        ->and(fn () => app(DeleteUser::class)->handle($this->j->superAdmin, $this->j->superAdmin))->toThrow(WorkflowRuleBroken::class);

    app(DeleteUser::class)->handle($this->j->superAdmin, $admin);
    expect($admin->refresh()->isDeleted())->toBeTrue();
});
