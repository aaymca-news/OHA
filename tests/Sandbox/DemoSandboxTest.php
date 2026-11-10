<?php

use App\Demo\DemoMail;
use App\Demo\DemoPeople;
use App\Demo\DemoVisitor;
use App\Demo\Sandboxes;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Movement;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoStorySeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The demo site: each visitor registers and gets a demo of their own (their own schema
 * and files), with everyone in it already (the Administrator, the AAYMCA assessor on
 * every movement, each movement's Board Chairperson and General Secretary). They choose
 * who to be, sign in again on any computer, choose a new password by email, start over,
 * or delete it all. Nobody sees anyone else's demo. Real schemas, cleaned up after each test.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true]);
    config(['oha.demo' => true, 'oha.demo_sandboxes' => true, 'mail.default' => 'array']);
    DemoVisitor::query()->delete();
});

afterEach(function () {
    Sandboxes::leave();
    foreach (Sandboxes::all() as $key) {
        Sandboxes::destroy($key);
    }
    DemoVisitor::query()->delete();
});

/** Registers a visitor through the page, and returns their demo's key. */
function register(string $email = 'ama@example.com', string $password = 'demo-pass-1'): string
{
    $response = test()->post(route('demo.register.store'), [
        'name' => 'Ama Mensah', 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

    return demoCookie($response);
}

function demoCookie($response): string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Sandboxes::COOKIE);
    expect($cookie)->not->toBeNull();

    return (string) $cookie->getValue();
}

/** The emails sent (to the "array" mailer). @return list<Symfony\Component\Mime\Email> */
function sentMails(): array
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
}

it('registers a visitor into a demo of their own, with everyone already in it', function () {
    $key = register();

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'))->assertOk()
        ->assertSee('Choose who to be')->assertSee('AAYMCA staff')->assertSee('A National Movement')
        ->assertSee('Zambia YMCA')->assertSee('Signed in to your demo as')
        ->assertDontSee('name="password"', false);

    Sandboxes::use($key);
    $assessor = User::query()->where('email', DemoPeople::STAFF['assessor']['email'])->firstOrFail();
    expect(User::query()->count())->toBe(48)
        ->and($assessor->assignedMovements()->count())->toBe(23)
        ->and(User::query()->where('role', Role::Board)->distinct()->count('movement_id'))->toBe(23)
        ->and(User::query()->where('role', Role::Ngs)->distinct()->count('movement_id'))->toBe(23)
        ->and(User::query()->where('email', 'not like', '%@example.org')->exists())->toBeFalse();
});

it('keeps each visitor’s demo to themselves', function () {
    $a = register('a@example.com');
    $b = register('b@example.com');
    expect($a)->not->toBe($b);

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('demo.sign-in'), ['role' => 'assessor'])->assertRedirect(route('dashboard'));
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $a)->post(route('assessments.store', $zambia), ['period_label' => 'Nov 2026', 'period_start' => '2026-11'])->assertRedirect();
    expect(Assessment::query()->count())->toBe(1);

    Sandboxes::use($b);
    expect(Assessment::query()->count())->toBe(0);
    Sandboxes::leave();
    expect(DB::connection('pgsql')->table('assessments')->count())->toBe(0);
});

it('signs a visitor in to the same demo on another computer', function () {
    $key = register('ama@example.com', 'demo-pass-1');

    $this->post(route('demo.sign-in.store'), ['email' => 'ama@example.com', 'password' => 'wrong-pass'])->assertSessionHasErrors('email');
    $response = $this->post(route('demo.sign-in.store'), ['email' => 'AMA@example.com', 'password' => 'demo-pass-1']);
    $response->assertRedirect(route('login'));
    expect(demoCookie($response))->toBe($key);

    // One account per email.
    $this->post(route('demo.register.store'), ['name' => 'Again', 'email' => 'ama@example.com', 'password' => 'demo-pass-2', 'password_confirmation' => 'demo-pass-2'])
        ->assertSessionHasErrors('email');
});

it('emails a visitor a link to choose a new password, and only to the email they registered', function () {
    $key = register('ama@example.com', 'demo-pass-1');

    $this->post(route('demo.password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status');
    expect(sentMails())->toBe([]);

    $this->post(route('demo.password.email'), ['email' => 'ama@example.com'])->assertSessionHas('status');
    $mails = sentMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTo()[0]->getAddress())->toBe('ama@example.com')
        ->and($mails[0]->getSubject())->toStartWith('[Demo] ')
        ->and($mails[0]->getTextBody())->toContain(DemoMail::NOTE);

    preg_match('#'.preg_quote(url('demo/account/reset-password'), '#').'/([A-Za-z0-9]+)\?email=[^\s\]]+#', (string) $mails[0]->getTextBody(), $m);
    expect($m)->not->toBeEmpty();
    $this->get($m[0])->assertOk()->assertSee('Choose a new password');

    $response = $this->post(route('demo.password.update'), ['token' => $m[1], 'email' => 'ama@example.com', 'password' => 'new-pass-22', 'password_confirmation' => 'new-pass-22']);
    $response->assertRedirect(route('login'));
    expect(demoCookie($response))->toBe($key);
    $this->post(route('demo.sign-in.store'), ['email' => 'ama@example.com', 'password' => 'new-pass-22'])->assertRedirect(route('login'));

    // The link works once.
    $this->post(route('demo.password.update'), ['token' => $m[1], 'email' => 'ama@example.com', 'password' => 'other-pass-33', 'password_confirmation' => 'other-pass-33'])
        ->assertSessionHasErrors('email');
});

it('goes from assessing Zambia, to signing its ODP as Zambia’s Board Chairperson, to seeing it signed as the Administrator', function () {
    config(['queue.default' => 'sync']);
    $key = register();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);

    // The AAYMCA assessor assesses Zambia and the Administrator approves each step, up to
    // the ODP (the platform's own steps, as in the demo's invented story).
    Sandboxes::use($key);
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $assessor = User::query()->where('email', DemoPeople::STAFF['assessor']['email'])->firstOrFail();
    $story = new DemoStorySeeder;
    (fn () => $this->admin = User::query()->where('email', DemoPeople::STAFF['admin']['email'])->firstOrFail())->call($story);
    try {
        $assessment = (fn () => $this->assessment($zambia, $assessor, 0.5, 2, 'odp_approved'))->call($story);
    } finally {
        Carbon::setTestNow();
    }
    $odp = $assessment->odp()->firstOrFail();
    $chair = DemoPeople::find('chair', $zambia);
    Sandboxes::leave();

    // As Zambia's Board Chairperson: the approved work is there to sign.
    $as()->post(route('demo.sign-in'), ['role' => 'chair', 'movement' => 'zambia'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($chair);
    $as()->get(route('dashboard'))->assertOk();
    $as()->post(route('artefacts.sign', $odp), ['signature' => $chair->name, 'confirm' => '1'])->assertSessionHasNoErrors();

    // Signing out goes back to "Choose who to be"; as the Administrator, it is signed.
    $as()->post(route('logout'))->assertRedirect(route('login'));
    $as()->post(route('demo.sign-in'), ['role' => 'admin'])->assertRedirect(route('dashboard'));
    $as()->get(route('dashboard'))->assertOk();
    Sandboxes::use($key);
    expect(BoardSignature::query()->where('signed_by', $chair->id)->exists())->toBeTrue();
});

it('starts a visitor over with a clean demo, keeping their account, and deletes it all on request', function () {
    // As on the server: sessions in the database (the old demo's are deleted with it).
    config(['session.driver' => 'database']);
    $old = register();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $old)->post(route('demo.sign-in'), ['role' => 'admin']);

    $response = $this->withUnencryptedCookie(Sandboxes::COOKIE, $old)->post(route('demo.start-over'));
    $response->assertRedirect(route('login'));
    $new = demoCookie($response);
    expect($new)->not->toBe($old)
        ->and(Sandboxes::exists($old))->toBeFalse()
        ->and(Sandboxes::exists($new))->toBeTrue()
        ->and(DemoVisitor::query()->email('ama@example.com')->value('sandbox_key'))->toBe($new);

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $new)->delete(route('demo.destroy'))->assertRedirect(route('home'));
    expect(Sandboxes::exists($new))->toBeFalse()
        ->and(DemoVisitor::query()->count())->toBe(0);
});

it('sends a visitor without a demo to the front page, whose buttons start one', function () {
    $this->get(route('login'))->assertRedirect(route('home'));
    $this->get(route('home'))->assertOk()->assertSee('Start your demo')->assertSee(route('demo.register'))
        ->assertDontSee('Sign in to your dashboard');
    $key = register();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('home'))->assertOk()->assertSee('Continue your demo');
});

it('has no invitations in the demo: everyone is already there', function () {
    $key = register();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('demo.sign-in'), ['role' => 'admin']);

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('admin.users.index'))->assertOk()->assertDontSee('Invite a user');
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('admin.users.create'))->assertNotFound();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('admin.users.store'), ['name' => 'Real Person', 'email' => 'real@example.com', 'role' => 'staff'])
        ->assertSessionHasErrors('action');
    Sandboxes::use($key);
    expect(User::query()->where('email', 'real@example.com')->exists())->toBeFalse();
});

it('deletes a demo and its account after 30 days unused, keeps at most the allowed number, and makes a new demo for a returning visitor', function () {
    $old = register('old@example.com');
    $recent = register('recent@example.com');
    DB::connection('pgsql')->table('demo_sandboxes')->where('key', $old)->update(['last_used_at' => now()->subDays(31)]);

    expect(Sandboxes::prune())->toBe(1)
        ->and(Sandboxes::exists($old))->toBeFalse()
        ->and(DemoVisitor::query()->email('old@example.com')->exists())->toBeFalse()
        ->and(DemoVisitor::query()->email('recent@example.com')->exists())->toBeTrue();

    // At the limit, the least recently used demo makes way; its visitor gets a new one on signing in.
    config(['oha.demo_sandbox_max' => 1]);
    register('newest@example.com');
    expect(Sandboxes::exists($recent))->toBeFalse();
    $response = $this->post(route('demo.sign-in.store'), ['email' => 'recent@example.com', 'password' => 'demo-pass-1']);
    expect(Sandboxes::exists(demoCookie($response)))->toBeTrue();
});

it('keeps the visitor’s session (who they are being) in their own demo, even on the sign-in pages', function () {
    // As on the server: sessions in the database. The sign-in page's controller opens the
    // session before the route's middleware runs, so the demo must be chosen before that.
    // (Made directly: in a test every request shares one app, and a page before this one
    // would already have opened its session; on the server each request starts afresh.)
    config(['session.driver' => 'database']);
    $key = Sandboxes::create();
    Sandboxes::leave();
    $mainBefore = DB::connection('pgsql')->table('public.sessions')->count();

    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'))->assertOk();

    expect(DB::connection('pgsql')->table('demo_'.$key.'.sessions')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('public.sessions')->count())->toBe($mainBefore);
});

it('shows the front page, not a loop, to someone still signed in from before demos had accounts', function () {
    config(['session.driver' => 'database']);
    DB::connection('pgsql')->beginTransaction();
    try {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $id = Str::random(40);
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'last_activity' => time(),
            'payload' => base64_encode((string) json_encode(['_token' => Str::random(40), 'login_web_'.sha1(SessionGuard::class) => $user->id])),
        ]);
        $this->withCookie((string) config('session.cookie'), $id)->get(route('home'))->assertOk()->assertSee('Start your demo');
        $this->withCookie('remember_web_'.sha1(SessionGuard::class), $user->id.'|old-remember-token|'.$user->password)
            ->get(route('home'))->assertOk()->assertSee('Start your demo');
    } finally {
        DB::connection('pgsql')->rollBack();
    }
});

it('is off on the live site', function () {
    config(['oha.demo' => false]);

    $this->get(route('demo.register'))->assertNotFound();
    $this->post(route('demo.sign-in.store'), ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
    $this->get(route('login'))->assertOk()->assertSee('name="password"', false)->assertDontSee('Choose who to be');
    $this->get(route('home'))->assertOk()->assertSee('Sign in to your dashboard')->assertSee('Access is by invitation only.')
        ->assertDontSee('Start your demo');
    expect(Sandboxes::all())->toBe([]);
});
