<?php

use App\Demo\DemoPeople;
use App\Demo\Sandboxes;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The demo site's private copies: each visitor works in their own copy (their own
 * schema and files), sees nobody else's work, can come back with a private link, start
 * over, and unused copies are deleted. Real schemas, cleaned up after each test.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true]);
    config(['oha.demo' => true, 'oha.demo_sandboxes' => true]);
});

afterEach(function () {
    Sandboxes::leave();
    foreach (Sandboxes::all() as $key) {
        Sandboxes::destroy($key);
    }
});

/** Starts a visitor's private copy through the page, and returns its key. */
function startDemo(): string
{
    $response = test()->post(route('demo.start'));
    $response->assertRedirect(route('login'));
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Sandboxes::COOKIE);
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

it('gives each visitor a private copy: what one does, the other never sees', function () {
    $a = startDemo();
    $b = startDemo();
    expect($a)->not->toBe($b);

    // Visitor A, as the assessor, starts Ghana's assessment in their copy.
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.sign-in', 'assessor'))->assertRedirect(route('dashboard'));
    $ghana = Movement::query()->where('slug', 'ghana')->firstOrFail();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('assessments.store', $ghana), ['period_label' => 'Nov 2026', 'period_start' => '2026-11'])->assertRedirect();
    expect(Assessment::query()->count())->toBe(1);

    // Visitor B's copy has no assessment, and the main schema none either.
    Sandboxes::use($b);
    expect(Assessment::query()->count())->toBe(0)
        ->and(Movement::query()->count())->toBe(23);
    Sandboxes::leave();
    expect(DB::connection('pgsql')->table('assessments')->count())->toBe(0);

    // Each copy has its own four demo people.
    Sandboxes::use($b);
    expect(User::query()->whereIn('email', array_column(DemoPeople::ACCOUNTS, 'email'))->count())->toBe(4);
});

it('sends a visitor without a private copy to the landing page to start one', function () {
    $this->get(route('login'))->assertRedirect(route('home'));
    $this->get(route('home'))->assertOk()->assertSee('Start your own demo');
});

it('brings a visitor back to their copy with its private link, and starts over with a clean one', function () {
    $a = startDemo();
    $this->get(route('demo.resume', $a))->assertRedirect(route('login'))->assertCookie(Sandboxes::COOKIE, $a, false);
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->get(route('login'))->assertOk()->assertSee('Copy your private demo link')->assertSee('Start over');

    $response = $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.start-over'));
    $response->assertRedirect(route('login'));
    $new = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Sandboxes::COOKIE)->getValue();
    expect($new)->not->toBe($a)
        ->and(Sandboxes::exists($a))->toBeFalse()
        ->and(Sandboxes::exists($new))->toBeTrue();
});

it('deletes copies unused for two weeks, and keeps at most the allowed number', function () {
    $old = startDemo();
    $recent = startDemo();
    DB::connection('pgsql')->table('demo_sandboxes')->where('key', $old)->update(['last_used_at' => now()->subDays(15)]);

    expect(Sandboxes::prune())->toBe(1)
        ->and(Sandboxes::exists($old))->toBeFalse()
        ->and(Sandboxes::exists($recent))->toBeTrue();

    // At the limit, the least recently used makes way.
    config(['oha.demo_sandbox_max' => 1]);
    $newest = startDemo();
    expect(Sandboxes::exists($recent))->toBeFalse()->and(Sandboxes::exists($newest))->toBeTrue();
});

it('is off on the live site', function () {
    config(['oha.demo' => false]);

    $this->post(route('demo.start'))->assertNotFound();
    $this->get(route('login'))->assertOk();
    expect(Sandboxes::all())->toBe([]);
});
