<?php

use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\ValidHtml;

/*
 * Every page, as every role: the right people get in (200), everyone else is
 * refused (403), and every page that renders has sound markup.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->assessment = $this->j->assessmentAt('odp_approved');

    $this->people = [
        'super admin' => $this->j->superAdmin,
        'admin' => $this->j->admin,
        'second admin' => $this->j->secondAdmin,
        'assessor' => $this->j->assessor,
        'other staff' => $this->j->otherStaff,
        'board chair' => $this->j->chair,
        'other board' => $this->j->ghanaChair,
    ];
});

// page => [route, params (closure over the test), roles that get 200; everyone else 403]
dataset('pages', [
    'dashboard' => ['dashboard', fn () => [], '*'],
    'my work' => ['my-work', fn () => [], '*'],
    'notifications' => ['notifications.index', fn () => [], '*'],
    'security' => ['security.show', fn () => [], '*'],
    'search' => ['search', fn () => ['q' => 'zam'], '*'],
    'movements list' => ['movements.index', fn () => [], 'secretariat'],
    'assessments list' => ['assessments.index', fn () => [], 'secretariat'],
    'timelines' => ['timelines', fn () => [], 'secretariat'],
    'users & roles' => ['admin.users.index', fn () => [], ['super admin', 'admin', 'second admin']],
    'invite a user' => ['admin.users.create', fn () => [], ['super admin', 'admin', 'second admin']],
    'Zambia profile' => ['movements.show', fn () => ['movement' => 'zambia'], 'secretariat+zambia board'],
    'Ghana profile' => ['movements.show', fn () => ['movement' => 'ghana'], 'secretariat+other board'],
    'form tab' => ['assessments.show', fn () => ['assessment' => test()->assessment->id, 'tab' => 'form'], 'secretariat+zambia board'],
    'report tab' => ['assessments.show', fn () => ['assessment' => test()->assessment->id, 'tab' => 'report'], 'secretariat+zambia board'],
    'odp tab' => ['assessments.show', fn () => ['assessment' => test()->assessment->id, 'tab' => 'odp'], 'secretariat+zambia board'],
    'timeline tab' => ['assessments.show', fn () => ['assessment' => test()->assessment->id, 'tab' => 'timeline'], 'secretariat+zambia board'],
    'audit tab' => ['assessments.show', fn () => ['assessment' => test()->assessment->id, 'tab' => 'audit'], 'secretariat+zambia board'],
]);

it('lets the right roles in, keeps everyone else out, and renders sound markup', function (string $route, Closure $params, string|array $allowed) {
    $secretariat = ['super admin', 'admin', 'second admin', 'assessor', 'other staff'];
    $allowed = match ($allowed) {
        '*' => array_keys($this->people),
        'secretariat' => $secretariat,
        'secretariat+zambia board' => [...$secretariat, 'board chair'],
        'secretariat+other board' => [...$secretariat, 'other board'],
        default => $allowed,
    };
    $url = route($route, $params());

    foreach ($this->people as $role => $user) {
        $response = $this->actingAs($user)->get($url);

        if (in_array($role, $allowed, true)) {
            expect($response->status())->toBe(200, "{$role} should open {$url}");
            ValidHtml::assert($response->getContent(), "{$url} as {$role}");
        } else {
            expect($response->status())->toBe(403, "{$role} should be refused {$url}");
        }
    }
})->with('pages');

it('shows the Board Chairperson the approved report and ODP, never the form or the audit trail', function () {
    $audit = route('assessments.show', ['assessment' => $this->assessment->id, 'tab' => 'audit']);

    $this->actingAs($this->j->chair)->get($audit)
        ->assertDontSee('Audit trail')->assertDontSee('Score check')->assertDontSee('1. OHA form')
        ->assertSee('2. Report')->assertSee('3. ODP');
});

it('shows the audit trail only to the Administrators and the movement’s assessors', function () {
    $audit = route('assessments.show', ['assessment' => $this->assessment->id, 'tab' => 'audit']);

    foreach (['assessor', 'admin', 'second admin', 'super admin'] as $role) {
        $this->actingAs($this->people[$role])->get($audit)->assertSee('Every step, who took it and when');
    }
    $this->actingAs($this->j->otherStaff)->get($audit)->assertDontSee('Every step, who took it and when');
});

it('offers each person only the buttons their role allows', function () {
    $odp = route('assessments.show', ['assessment' => $this->assessment->id, 'tab' => 'odp']);
    $report = route('assessments.show', ['assessment' => $this->assessment->id, 'tab' => 'report']);

    $this->actingAs($this->j->chair)->get($odp)->assertSee('Sign and validate')->assertSee('Not validated');
    $this->actingAs($this->j->assessor)->get($odp)->assertDontSee('Sign and validate')->assertSee('does not hold up the Secretariat');
    $this->actingAs($this->j->admin)->get($odp)->assertDontSee('Release to the board')->assertDontSee('Sign and validate');

    $this->actingAs($this->j->assessor)->get($report)->assertSee('Upload a new version');
    $this->actingAs($this->j->otherStaff)->get($report)->assertDontSee('Upload a new version')->assertDontSee('Your approval');
    $this->actingAs($this->j->chair)->get($report)->assertDontSee('Upload a new version')->assertDontSee('Sign and validate');
});

it('shows Validated once the Chairperson has signed', function () {
    $this->j->sign($this->j->odp($this->assessment));

    $this->actingAs($this->j->otherStaff)->get(route('assessments.show', ['assessment' => $this->assessment->id, 'tab' => 'odp']))
        ->assertSee('Validated by the board')->assertSee('Naledi Moyo');
});

it('shows the sidebar each role needs, and the warning to those who must act on it', function () {
    $this->actingAs($this->j->assessor)->get(route('dashboard'))->assertSee('National Movements')->assertSee('Timelines')
        ->assertDontSee('Users &amp; Roles', escape: false)->assertDontSee('Roles still to be appointed');
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertSee('Users &amp; Roles', escape: false)->assertSee('Roles still to be appointed');
    $this->actingAs($this->j->superAdmin)->get(route('dashboard'))->assertSee('Roles still to be appointed');
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertSee('Our Movement')->assertDontSee('National Movements')->assertDontSee('Movements assessed');
});
