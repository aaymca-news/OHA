<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\ChairRoleEnded;
use App\Notifications\InvitationNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * A movement has one Board Chairperson. Inviting a new one for a movement that has one
 * is a deliberate replacement: the current Chairperson is deactivated and told by email.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->invite = fn (array $fields) => $this->actingAs($this->j->admin)->post(route('admin.users.store'), $fields + [
        'name' => 'Mutale Banda', 'email' => 'mutale.banda@zambiaymca.org', 'role' => 'board', 'title' => 'Board Chairperson',
    ]);
});

it('greys out movements that already have a Chairperson unless replacing, and names who holds it', function () {
    $this->actingAs($this->j->admin)->get(route('admin.users.create'))->assertOk()
        ->assertSee('Zambia YMCA — Chairperson: Naledi Moyo')
        ->assertSee('For a movement')
        ->assertSee('Replacing')
        ->assertSee('Movements that already have a Board Chairperson are greyed out', escape: false);
});

it('refuses a second Chairperson for a movement unless it is a replacement', function () {
    ($this->invite)(['chair_mode' => 'new', 'board_movement_id' => $this->j->zambia->id])
        ->assertSessionHasErrors(['action' => 'Naledi Moyo is Zambia YMCA’s Board Chairperson, and a movement has one. Confirm to replace them: their account will be deactivated.']);

    expect(User::query()->where('email', 'mutale.banda@zambiaymca.org')->exists())->toBeFalse()
        ->and($this->j->chair->refresh()->active)->toBeTrue();
});

it('replaces the Chairperson: the old one is deactivated, signed out and emailed; the new one is invited', function () {
    DB::table('sessions')->insert(['id' => 'old-chair-session', 'user_id' => $this->j->chair->id, 'payload' => '', 'last_activity' => time()]);

    ($this->invite)(['chair_mode' => 'replace', 'board_movement_id' => $this->j->zambia->id])->assertRedirect();

    $new = User::query()->where('email', 'mutale.banda@zambiaymca.org')->firstOrFail();
    expect($this->j->chair->refresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->j->chair->id)->exists())->toBeFalse()
        ->and($this->j->zambia->chair()->first()->id)->toBe($new->id)
        ->and(AuditEvent::query()->where('action', 'user.deactivated')->value('payload')['reason'])->toBe('Replaced as Zambia YMCA’s Board Chairperson by Mutale Banda');

    // Told even though their account is now deactivated.
    Notification::assertSentTo($this->j->chair, ChairRoleEnded::class, function (ChairRoleEnded $n) {
        $mail = $n->toMail($this->j->chair);

        return $mail->subject === 'Your role as Board Chairperson of Zambia YMCA on the OHA platform has ended'
            && $mail->greeting === 'Dear Naledi,'
            && str_contains(implode(' ', $mail->introLines), 'Gloria Anyika has appointed Mutale Banda as Board Chairperson of Zambia YMCA');
    });
    Notification::assertSentTo($new, InvitationNotice::class, fn (InvitationNotice $n) => $n->chairOf === 'Zambia YMCA'
        && str_contains(implode(' ', $n->toMail($new)->introLines), 'Board Chairperson of Zambia YMCA'));
});

it('invites a Chairperson for a movement without one, with no one replaced', function () {
    $ghanaChair = $this->j->ghanaChair;
    $ghanaChair->update(['active' => false]);

    ($this->invite)(['chair_mode' => 'new', 'board_movement_id' => $this->j->ghana->id])->assertRedirect();

    Notification::assertNotSentTo($ghanaChair, ChairRoleEnded::class);
    expect($this->j->ghana->chair()->first()->email)->toBe('mutale.banda@zambiaymca.org');
});
