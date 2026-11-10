<?php

use App\Demo\DemoPeople;
use App\Demo\Sandboxes;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

    // Visitor A gives the assessor Ghana (as their Administrator would), then, as the
    // assessor, starts Ghana's assessment in their copy.
    Sandboxes::use($a);
    $ghana = Movement::query()->where('slug', 'ghana')->firstOrFail();
    User::query()->where('email', DemoPeople::ACCOUNTS['assessor']['email'])->firstOrFail()->assignedMovements()->attach($ghana->id);
    Sandboxes::leave();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.sign-in', 'assessor'))->assertRedirect(route('dashboard'));
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

it('sends a visitor without a private copy to the landing page, whose buttons start one', function () {
    $this->get(route('login'))->assertRedirect(route('home'));
    // The landing page's sign-in buttons start a demo instead; once started, they continue it.
    $this->get(route('home'))->assertOk()->assertSee('Start your own demo')
        ->assertSee('action="'.route('demo.start').'"', false)
        ->assertDontSee('Sign in to your dashboard');
    $a = startDemo();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->get(route('home'))->assertOk()
        ->assertSee('Continue your demo')->assertDontSee('Start your own demo');
});

it('keeps the visitor’s session (their sign-in) in their own copy, even on the sign-in pages', function () {
    // As on the server: sessions in the database. The sign-in page's controller opens the
    // session before the route's middleware runs, so the copy must be chosen before that.
    config(['session.driver' => 'database']);
    $key = Sandboxes::create();
    Sandboxes::leave();
    $mainBefore = DB::connection('pgsql')->table('public.sessions')->count();

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'))->assertOk();

    expect(DB::connection('pgsql')->table('demo_'.$key.'.sessions')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('public.sessions')->count())->toBe($mainBefore);
});

it('shows the landing page, not a loop, to someone still signed in from before private copies', function () {
    // A sign-in kept in the main schema, as on the demo when it was one shared space.
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $id = Str::random(40);
    DB::connection('pgsql')->table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => base64_encode((string) json_encode(['_token' => Str::random(40), 'login_web_'.sha1(SessionGuard::class) => $user->id])),
        'last_activity' => time(),
    ]);

    try {
        $this->withCookie((string) config('session.cookie'), $id)->get(route('home'))
            ->assertOk()->assertSee('Start your own demo');
    } finally {
        DB::connection('pgsql')->table('sessions')->where('id', $id)->delete();
        $user->delete();
    }
});

it('shows the landing page, not a loop, to someone with an old "remember me" sign-in', function () {
    // In a transaction, undone after: a sign-in it allowed would leave a trail in the main schema.
    DB::connection('pgsql')->beginTransaction();
    try {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $this->withCookie('remember_web_'.sha1(SessionGuard::class), $user->id.'|old-remember-token|'.$user->password)
            ->get(route('home'))->assertOk()->assertSee('Start your own demo');
    } finally {
        DB::connection('pgsql')->rollBack();
    }
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
    $this->get(route('home'))->assertOk()->assertSee('Sign in to your dashboard')->assertSee('Access is by invitation only.')
        ->assertDontSee('Start your own demo')->assertDontSee('Continue your demo');
    expect(Sandboxes::all())->toBe([]);
});

/** The emails the demo sent (to the "array" mailer). @return list<Symfony\Component\Mime\Email> */
function demoMails(): array
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
}

it('starts a demo with only the four demo people, the assessor assigned to nothing', function () {
    $a = startDemo();
    Sandboxes::use($a);

    expect(User::query()->count())->toBe(4)
        ->and(User::query()->where('email', DemoPeople::ACCOUNTS['assessor']['email'])->first()->assignedMovements()->count())->toBe(0);
});

it('invites people like the live site: a real email marked Demo, whose link opens the inviter’s demo', function () {
    config(['mail.default' => 'array']);
    Cache::store('demo')->flush();
    $a = startDemo();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.sign-in', 'admin'));
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('admin.users.store'), [
        'name' => 'Ama Mensah', 'email' => 'ama@example.com', 'role' => 'staff',
    ])->assertSessionHasNoErrors()->assertRedirect();

    // One email, to the invited person, marked as the demo's.
    $mails = demoMails();
    expect($mails)->toHaveCount(1);
    $mail = $mails[0];
    expect($mail->getTo()[0]->getAddress())->toBe('ama@example.com')
        ->and($mail->getSubject())->toStartWith('[Demo] ')
        ->and($mail->getHtmlBody())->toContain('It is not from the live platform')
        ->and($mail->getTextBody())->toContain('It is not from the live platform');

    // Its link goes through the inviter's demo, then on to setting a password.
    preg_match('#'.preg_quote(route('demo.resume', $a), '#').'\?next=%2Finvitation[^\s\]]+#', (string) $mail->getTextBody(), $m);
    expect($m)->not->toBeEmpty();
    // The invited person is someone else, in another browser: nobody signed in.
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('logout'));
    Sandboxes::leave();
    $response = $this->get($m[0]);
    $response->assertCookie(Sandboxes::COOKIE, $a, false);
    $next = $response->headers->get('Location');
    expect(parse_url((string) $next, PHP_URL_PATH))->toStartWith('/invitation/');

    // The invited person sets a password and carries on, in that demo.
    parse_str((string) parse_url((string) $next, PHP_URL_QUERY), $query);
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->get($next)->assertOk();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('invitation.store'), [
        'token' => basename((string) parse_url((string) $next, PHP_URL_PATH)), 'email' => $query['email'],
        'password' => 'Demo-Password-2026', 'password_confirmation' => 'Demo-Password-2026',
    ])->assertRedirect(route('dashboard'));
    Sandboxes::use($a);
    expect(User::query()->where('email', 'ama@example.com')->first()->isInvitationPending())->toBeFalse();
});

it('never emails the demo people, and limits invitations a day', function () {
    config(['mail.default' => 'array', 'oha.demo_invites_per_demo' => 1]);
    Cache::store('demo')->flush();
    $a = startDemo();

    Sandboxes::use($a);
    Mail::raw('A notice.', fn ($m) => $m->to(DemoPeople::ACCOUNTS['ngs']['email'])->subject('Notice'));
    expect(demoMails())->toBe([]);
    Sandboxes::leave();

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.sign-in', 'admin'));
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('admin.users.store'), ['name' => 'One', 'email' => 'one@example.com', 'role' => 'staff'])
        ->assertSessionHasNoErrors();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('admin.users.store'), ['name' => 'Two', 'email' => 'two@example.com', 'role' => 'staff'])
        ->assertSessionHasErrors('action');
    Sandboxes::use($a);
    expect(User::query()->where('email', 'two@example.com')->exists())->toBeFalse()
        ->and(demoMails())->toHaveCount(1);
});

it('opens only pages on this site from a private link', function () {
    $a = startDemo();
    $this->get(route('demo.resume', ['key' => $a, 'next' => '//elsewhere.example/x']))->assertRedirect(route('login'));
    $this->get(route('demo.resume', ['key' => $a, 'next' => '/\elsewhere.example']))->assertRedirect(route('login'));
    $this->get(route('demo.resume', ['key' => $a, 'next' => '/dashboard']))->assertRedirect(url('/dashboard'));
});
