<?php

use App\Models\Movement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Navigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Ready for more movements: the busiest pages ask the database about the same number of
 * questions however many movements there are (no query per row), and the sidebar's
 * badges are kept between pages until something changes.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->j->assessmentAt('form_approved');
});

/** How many queries a page takes to draw, for this person. */
function queriesFor(User $user, string $url): int
{
    test()->actingAs($user)->get($url)->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($user)->get($url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('draws the busiest pages with about the same number of queries for ten more movements', function (string $route, string $who) {
    $user = $this->j->{$who};
    $url = route($route);
    $before = queriesFor($user, $url);

    Movement::factory()->count(10)->create();
    $after = queriesFor($user, $url);

    // A few more are fine (a lookup per page section); one or more per movement is not.
    expect($after - $before)->toBeLessThan(10);
})->with([
    'National Movements' => ['movements.index', 'admin'],
    'Dashboard' => ['dashboard', 'admin'],
    'Assessments' => ['assessments.index', 'admin'],
    'Timelines' => ['timelines', 'admin'],
    'My Work' => ['my-work', 'assessor'],
]);

it('keeps the sidebar badges between pages, and counts them again once anything changes', function () {
    config(['oha.badge_cache_seconds' => 60]);
    $nav = app(Navigation::class);
    $badge = fn () => collect($nav->for($this->j->assessor))->firstWhere('route', 'my-work')['badge'];

    $first = $badge();
    DB::enableQueryLog();
    expect($badge())->toBe($first)->and(DB::getQueryLog())->toBe([]); // kept: no query
    DB::disableQueryLog();

    // Something recorded in the audit trail: worked out afresh.
    Audit::record($this->j->admin, 'test.changed', $this->j->zambia);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $badge();
    expect(DB::getQueryLog())->not->toBe([]);
    DB::disableQueryLog();
});

it('serves the cached sidebar again to a movement’s own users (plain values only in the cache)', function () {
    // As on the live site: the database cache, with the sidebar kept between pages.
    config(['cache.default' => 'database', 'oha.badge_cache_seconds' => 60]);
    $ngs = User::factory()->generalSecretary($this->j->zambia)->create();

    foreach ([$this->j->chair, $ngs] as $user) {
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee(route('movements.show', $this->j->zambia), false);
    }
});
