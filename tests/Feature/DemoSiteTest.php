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

it('starts the demo with the 23 movements, no assessments, and the four demo accounts', function () {
    $this->seed(DemoSeeder::class);

    expect(Movement::query()->count())->toBe(23)
        ->and(Assessment::query()->count())->toBe(0);
    foreach (DemoPeople::ACCOUNTS as $account) {
        expect(User::query()->where('email', $account['email'])->exists())->toBeTrue();
    }
    expect(User::query()->where('email', 'not like', '%@example.org')->exists())->toBeFalse();
    expect(User::query()->where('email', DemoPeople::ACCOUNTS['chair']['email'])->first()->movement->slug)->toBe(DemoPeople::MOVEMENT);
});

it('signs in with one click on the demo site, and nowhere else', function () {
    $this->seed(DemoSeeder::class);

    // Off (as on the live site): no buttons, no banner, and the sign-in answers 404.
    config(['oha.demo' => false]);
    $this->get(route('login'))->assertOk()->assertDontSee('Explore the demo as')->assertDontSee('Demo site, for trying the platform');
    $this->post(route('demo.sign-in', 'admin'))->assertNotFound();
    $this->assertGuest();

    // On: the buttons and the banner, and one click signs in.
    config(['oha.demo' => true]);
    $this->get(route('login'))->assertOk()->assertSee('Explore the demo as')->assertSee('Demo site, for trying the platform')->assertSee('National General Secretary');
    $this->post(route('demo.sign-in', 'ngs'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::query()->where('email', DemoPeople::ACCOUNTS['ngs']['email'])->first());
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
