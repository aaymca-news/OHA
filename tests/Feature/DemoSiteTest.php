<?php

use App\Console\Commands\DemoFreshStart;
use App\Demo\DemoMovements;
use App\Demo\DemoPeople;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoStorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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

it('gives the demo’s movements made-up names, Sample YMCA 1 to 23, with their people, and keeps them when seeded again', function () {
    config(['oha.demo' => true]);
    $this->seed(DemoSeeder::class);

    $names = Movement::query()->pluck('name')->sort(SORT_NATURAL)->values()->all();
    expect($names)->toBe(collect(range(1, 23))->map(fn ($n) => "Sample YMCA {$n}")->all());

    // The rest as loaded; the demo people for it take the sample name, and no real name is left.
    $sample = Movement::query()->where('slug', DemoMovements::slugFor('zambia'))->firstOrFail();
    $chair = DemoPeople::find('chair', $sample);
    expect($sample->name)->toBe('Sample YMCA 16')->and($sample->country)->toBe('Zambia')
        ->and($chair->title)->toBe('Board Chairperson, Sample YMCA 16 (demo)')
        ->and($chair->email)->toEndWith('.sample-ymca-16@example.org')
        ->and(User::query()->where('title', 'like', '%Zambia YMCA%')->orWhere('email', 'like', '%.zambia@example.org')->exists())->toBeFalse();

    // Seeding again adds nothing (no real-named movements come back).
    $this->seed(DemoSeeder::class);
    expect(Movement::query()->count())->toBe(23)->and(User::query()->count())->toBe(48);

    // "Choose who to be" lists the sample names only.
    $this->get(route('login'))->assertOk()->assertSee('Sample YMCA 16')->assertDontSee('Zambia YMCA');
});

it('starts the demo afresh under the sample names: every assessment, file, notice and history entry cleared, the accounts kept', function () {
    Storage::fake('oha');
    // A demo with work in it, under the real names (as the demo site has today).
    $this->seed(DemoStorySeeder::class);
    Storage::disk('oha')->put('avatars/1/photo.jpg', 'a photo');
    $people = User::query()->count();
    expect(Assessment::query()->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_events')->count())->toBeGreaterThan(0)
        ->and(DB::table('notifications')->count())->toBeGreaterThan(0)
        ->and(Storage::disk('oha')->allFiles())->not->toHaveCount(1);

    config(['oha.demo' => true]);
    $this->artisan('oha:demo-fresh-start', ['--force' => true])->assertSuccessful();

    foreach (DemoFreshStart::CLEARED as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
    expect(Movement::query()->where('name', 'not like', 'Sample YMCA %')->exists())->toBeFalse()
        ->and(Movement::query()->count())->toBe(23)
        ->and(User::query()->count())->toBe($people)
        ->and(Storage::disk('oha')->allFiles())->toBe(['avatars/1/photo.jpg']);

    // The Administrator sees every movement as not yet assessed, under its sample name.
    $this->actingAs(User::query()->where('email', DemoPeople::STAFF['admin']['email'])->firstOrFail())
        ->get(route('movements.index'))->assertOk()->assertSee('Sample YMCA 16')->assertDontSee('Zambia YMCA');
});

it('never starts the demo afresh outside the demo site', function () {
    config(['oha.demo' => false]);

    $this->artisan('oha:demo-fresh-start', ['--force' => true])->assertFailed();
});
