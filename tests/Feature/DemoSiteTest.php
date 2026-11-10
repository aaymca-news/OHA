<?php

use App\Demo\DemoPeople;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoStorySeeder;
use Illuminate\Support\Facades\Notification;

/*
 * The demo site (demo.oha.africaymca.org): the same platform with a banner, one-click
 * sign-in for each role, no email, and the demo accounts; none of it on the live site.
 */

// These check the demo as one shared space; the private copies have their own tests (tests/Sandbox).
beforeEach(fn () => config(['oha.demo_sandboxes' => false]));

it('starts the demo with the 23 movements, no assessments, and everyone already there', function () {
    $this->seed(DemoSeeder::class);

    expect(Movement::query()->count())->toBe(23)
        ->and(Assessment::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(48);
    foreach (DemoPeople::STAFF as $person) {
        expect(User::query()->where('email', $person['email'])->exists())->toBeTrue();
    }
    expect(User::query()->where('email', 'not like', '%@example.org')->exists())->toBeFalse();
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    expect(DemoPeople::find('chair', $zambia)?->movement_id)->toBe($zambia->id)
        ->and(DemoPeople::find('ngs', $zambia)?->movement_id)->toBe($zambia->id);
});

it('signs in with one click on the demo site, and nowhere else', function () {
    $this->seed(DemoSeeder::class);

    // Off (as on the live site): no choices, no banner, and the sign-in answers 404.
    config(['oha.demo' => false]);
    $this->get(route('login'))->assertOk()->assertDontSee('Choose who to be')->assertDontSee('Demo site, for trying the platform');
    $this->post(route('demo.sign-in'), ['role' => 'admin'])->assertNotFound();
    $this->assertGuest();

    // On: the choices and the banner, and one click signs in, here as a movement's NGS.
    config(['oha.demo' => true]);
    $this->get(route('login'))->assertOk()->assertSee('Choose who to be')->assertSee('Demo site, for trying the platform')->assertSee('National General Secretary');
    $this->post(route('demo.sign-in'), ['role' => 'ngs'])->assertSessionHasErrors('movement');
    $this->post(route('demo.sign-in'), ['role' => 'ngs', 'movement' => 'ghana'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(DemoPeople::find('ngs', Movement::query()->where('slug', 'ghana')->firstOrFail()));
    $this->get(route('dashboard'))->assertOk()->assertSee('Demo site, for trying the platform');
});

it('never resets anything outside the demo site', function () {
    config(['oha.demo' => false]);

    $this->artisan('oha:demo-reset', ['--force' => true])->assertFailed();
});

it('builds the optional invented story through the real workflow, ready for later', function () {
    Notification::fake();
    $this->seed(DemoStorySeeder::class);

    $ghana = Movement::query()->where('slug', 'ghana')->firstOrFail();
    $signed = $ghana->lastAssessmentDone();
    expect($signed)->not->toBeNull()
        ->and($signed->odpTasks()->count())->toBeGreaterThan(5)
        ->and($signed->followUps()->count())->toBeGreaterThan(0)
        ->and(Movement::query()->where('slug', 'south-africa')->first()->followUpPaused())->toBeTrue()
        ->and(Movement::query()->where('slug', 'benin')->first()->assessmentUnderWay())->not->toBeNull()
        ->and(Assessment::query()->where('movement_id', Movement::query()->where('slug', 'zambia')->value('id'))->count())->toBe(2);
});
