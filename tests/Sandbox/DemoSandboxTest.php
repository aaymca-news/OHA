<?php

use App\Demo\DemoForm;
use App\Demo\DemoMail;
use App\Demo\DemoOdp;
use App\Demo\DemoPeople;
use App\Demo\DemoVisitor;
use App\Demo\Sandboxes;
use App\Enums\Role;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Movement;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoStorySeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The demo site: each visitor registers and gets a demo of their own (their own schema
 * and files), a copy of the showcase the Super Administrator prepares, with everyone in
 * it already (the Administrator, the AAYMCA assessor on every movement, each movement's
 * Board Chairperson and General Secretary). They choose who to be, sign in again on any
 * computer, choose a new password by email, start over (from the latest showcase), or
 * delete it all. Nobody sees anyone else's demo. Real schemas, cleaned up after each test.
 */

beforeEach(function () {
    config([
        'oha.demo' => true, 'oha.demo_sandboxes' => true, 'mail.default' => 'array',
        'oha.demo_showcase_folder' => storage_path('framework/testing/demo-showcase'),
    ]);
    // A fresh showcase (the main schema): the movements and the demo people, no work yet.
    $this->artisan('migrate:fresh', ['--force' => true]);
    $this->seed(DemoSeeder::class);
    File::deleteDirectory((string) config('oha.demo_showcase_folder'));
    config(['filesystems.disks.oha.root' => config('oha.demo_showcase_folder')]);
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
        ->and(User::query()->where('email', 'not like', '%@example.org')->exists())->toBeFalse()
        // Everyone carries the visitor's own name; their role and title tell them apart.
        ->and(User::query()->where('name', '!=', 'Ama Mensah')->exists())->toBeFalse()
        ->and(DemoPeople::find('chair', Movement::query()->where('slug', 'zambia')->firstOrFail())->title)->toBe('Board Chairperson, Zambia YMCA (demo)');
});

it('lets a visitor be the Administrator and the assessor even if they were deactivated in the showcase', function () {
    User::query()->whereIn('email', array_column(DemoPeople::STAFF, 'email'))->update(['active' => false]);
    $key = register();

    foreach (array_keys(DemoPeople::STAFF) as $role) {
        $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('demo.sign-in'), ['role' => $role])->assertRedirect(route('dashboard'));
        $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('demo.choose'))->assertRedirect(route('login'));
    }
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
    // A demo made before everyone carried the visitor's name gets it on signing in.
    DB::connection('pgsql')->table('demo_'.$key.'.users')->update(['name' => 'Someone Else']);
    $response = $this->post(route('demo.sign-in.store'), ['email' => 'AMA@example.com', 'password' => 'demo-pass-1']);
    $response->assertRedirect(route('login'));
    expect(demoCookie($response))->toBe($key)
        ->and(DB::connection('pgsql')->table('demo_'.$key.'.users')->where('name', '!=', 'Ama Mensah')->exists())->toBeFalse();

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

/** The showcase's Super Administrator, made with the command (the password typed in, as on the server). */
function makeSuperAdmin(): User
{
    test()->artisan('oha:demo-super-admin', ['email' => 'superadmin.demo@africaymca.org', 'name' => 'Ray'])
        ->expectsQuestion('Password (not shown as you type)', 'Showcase-Pass-2026')
        ->expectsQuestion('The same password again', 'Showcase-Pass-2026')
        ->assertSuccessful();

    return User::query()->where('email', 'superadmin.demo@africaymca.org')->firstOrFail();
}

/** Brings a movement's assessment to a step ("ODP approved" unless told) in the showcase: the AAYMCA assessor's steps, approved by the Super Administrator. */
function prepareInShowcase(string $slug, User $superAdmin, string $to = 'odp_approved'): Assessment
{
    config(['queue.default' => 'sync']);
    Sandboxes::leave();
    $story = new DemoStorySeeder;
    (fn () => $this->admin = $superAdmin)->call($story);
    try {
        return (fn () => $this->assessment(Movement::query()->where('slug', $slug)->firstOrFail(),
            User::query()->where('email', DemoPeople::STAFF['assessor']['email'])->firstOrFail(), 0.5, 2, $to))->call($story);
    } finally {
        Carbon::setTestNow();
    }
}

it('lets the Super Administrator, and only them, sign in to the showcase with their own email and password', function () {
    $ray = makeSuperAdmin();
    expect($ray->role)->toBe(Role::SuperAdmin)
        ->and($ray->isInvitationPending())->toBeFalse()
        ->and(User::query()->where('role', Role::SuperAdmin)->count())->toBe(1);

    $this->get(route('demo.showcase'))->assertRedirect(route('login'))->assertCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE, false);
    $showcase = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE);
    $showcase()->get(route('login'))->assertOk()->assertSee('Showcase sign-in')->assertSee('name="password"', false)->assertDontSee('Choose who to be');

    // Nobody is one click away in the showcase, and the demo people have no password.
    $showcase()->post(route('demo.sign-in'), ['role' => 'admin'])->assertNotFound();
    $showcase()->post(route('login'), ['email' => DemoPeople::STAFF['admin']['email'], 'password' => 'anything'])->assertSessionHasErrors();
    $this->assertGuest();

    $showcase()->post(route('login'), ['email' => 'superadmin.demo@africaymca.org', 'password' => 'Showcase-Pass-2026'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($ray);
    $showcase()->get(route('dashboard'))->assertOk()->assertSee(route('admin.users.index'))->assertSee('Showcase:');
    $showcase()->post(route('logout'));

    // In a visitor's demo, there is no signing in as the Super Administrator.
    $key = register();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('login'), ['email' => 'superadmin.demo@africaymca.org', 'password' => 'Showcase-Pass-2026'])
        ->assertSessionHasErrors();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'))->assertOk()->assertDontSee('Super Administrator');
    Sandboxes::use($key);
    expect(DemoPeople::find('admin')->role)->toBe(Role::Admin)
        ->and(User::query()->where('email', 'superadmin.demo@africaymca.org')->value('name'))->toBe('Ray');
});

it('shows the Super Administrator, in the showcase, everyone who registered for a demo; nobody else sees them', function () {
    $ray = makeSuperAdmin();
    $key = register('ama@example.com');
    register('kwame@example.com');

    $showcase = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE);
    $showcase()->post(route('login'), ['email' => $ray->email, 'password' => 'Showcase-Pass-2026']);
    $showcase()->get(route('admin.users.index'))->assertOk()->assertSee('Demo visitors')->assertSee('Wanjiru Kamau');
    $showcase()->get(route('admin.users.index', ['show' => 'visitors']))->assertOk()
        ->assertSee('ama@example.com')->assertSee('kwame@example.com')->assertSee('In use');
    $showcase()->post(route('logout'));

    // A visitor's own Administrator: no such tab, and the list answers 404.
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->post(route('demo.sign-in'), ['role' => 'admin']);
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('admin.users.index'))->assertOk()->assertDontSee('Demo visitors');
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('admin.users.index', ['show' => 'visitors']))->assertNotFound();
});

it('lets the Super Administrator start an assessment in the showcase, which visitors carry on in their copies', function () {
    $ray = makeSuperAdmin();
    expect($ray->assignedMovements()->count())->toBe(23);

    // Ray starts Zambia's assessment in the showcase.
    $showcase = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE);
    $showcase()->post(route('login'), ['email' => $ray->email, 'password' => 'Showcase-Pass-2026']);
    $showcase()->post(route('assessments.store', Movement::query()->where('slug', 'zambia')->firstOrFail()), ['period_label' => 'Nov 2026', 'period_start' => '2026-11'])
        ->assertSessionHasNoErrors();
    $showcase()->post(route('logout'));
    $assessment = Assessment::query()->firstOrFail();
    $form = $assessment->form()->firstOrFail();

    // A visitor, as the AAYMCA assessor, uploads the movement's form to it in their copy.
    $key = register();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);
    $as()->post(route('demo.sign-in'), ['role' => 'assessor']);
    $file = DemoForm::make('Zambia YMCA', 'Zambia', 2026, 0.6, 7);
    try {
        $as()->post(route('artefacts.upload', $form), ['form' => new UploadedFile($file, 'Zambia OHA Form 2026.xlsx', null, null, true)])
            ->assertSessionHasNoErrors();
    } finally {
        @unlink($file);
    }

    // As the Administrator, the visitor sees it on the assessment.
    $as()->post(route('demo.choose'));
    $as()->post(route('demo.sign-in'), ['role' => 'admin']);
    $as()->get(route('assessments.show', $assessment))->assertOk()->assertSee('Zambia OHA Form 2026.xlsx');
    Sandboxes::use($key);
    expect(DB::table('form_uploads')->count())->toBe(1);

    // The showcase is as Ray left it.
    Sandboxes::leave();
    expect(DB::table('form_uploads')->count())->toBe(0);
});

it('shows Zambia’s Board Chairperson and NGS what was approved, by the Super Administrator in the showcase or by the visitor as the Administrator', function () {
    // In the showcase: Zambia's form approved by the Super Administrator, its report sent for approval.
    $assessment = prepareInShowcase('zambia', makeSuperAdmin(), 'report_submitted');
    $formName = $assessment->form()->firstOrFail()->uploads()->whereNotNull('approved_at')->value('original_name');
    $report = $assessment->report()->firstOrFail();
    $reportName = (string) $report->latestVersion()->value('original_name');

    $key = register();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);
    $zambia = ['movement' => 'zambia'];

    // As Zambia's NGS and Board Chairperson: the approved form, not yet the report.
    foreach (['ngs', 'chair'] as $role) {
        $as()->post(route('demo.sign-in'), ['role' => $role] + $zambia)->assertRedirect(route('dashboard'));
        $as()->get(route('resources'))->assertOk()->assertSee($formName)->assertDontSee($reportName);
        $as()->post(route('demo.choose'));
    }

    // As the Administrator, the visitor approves the report.
    $as()->post(route('demo.sign-in'), ['role' => 'admin']);
    $as()->post(route('artefacts.approve', $report))->assertSessionHasNoErrors();
    $as()->post(route('demo.choose'));

    // Zambia's NGS and Board Chairperson now see the report too.
    foreach (['ngs', 'chair'] as $role) {
        $as()->post(route('demo.sign-in'), ['role' => $role] + $zambia);
        $as()->get(route('resources'))->assertOk()->assertSee($formName)->assertSee($reportName);
        $as()->post(route('demo.choose'));
    }
});

it('lets the ODP be uploaded without a Google Drive link on the demo', function () {
    $assessment = prepareInShowcase('zambia', makeSuperAdmin(), 'report_approved');
    $odp = $assessment->odp()->firstOrFail();

    $key = register();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);
    $as()->post(route('demo.sign-in'), ['role' => 'assessor']);
    $as()->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))->assertOk()->assertSee('(optional on the demo)');

    $file = DemoOdp::make('Zambia YMCA', now(), [], 11);
    try {
        $as()->post(route('artefacts.versions', $odp), ['odp' => new UploadedFile($file, 'Zambia ODP 2026.xlsx', null, null, true)])
            ->assertSessionHasNoErrors();
    } finally {
        @unlink($file);
    }

    // (Looked up afresh: a model read in the showcase stays on the showcase's connection.)
    Sandboxes::use($key);
    $mine = Artefact::query()->findOrFail($odp->id);
    expect($mine->versions()->count())->toBe(1)
        ->and($mine->drive_url)->toBeNull();
});

it('tells the Super Administrator what a visitor sends for approval, and lets them go into that demo, approve it as themselves, and come back', function () {
    config(['session.driver' => 'database']);
    $ray = makeSuperAdmin();
    $assessment = prepareInShowcase('zambia', $ray, 'form_uploaded');
    $form = $assessment->form()->firstOrFail();

    // A visitor, as the AAYMCA assessor, sends Zambia's form for approval in their demo.
    $key = register();
    $visitor = DemoVisitor::query()->email('ama@example.com')->firstOrFail();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);
    $as()->post(route('demo.sign-in'), ['role' => 'assessor']);
    $as()->post(route('artefacts.submit', $form), ['acknowledge_gaps' => '1', 'reason' => 'Figures to follow.', 'seen' => $form->currentUpload()->value('id')])
        ->assertSessionHasNoErrors();
    $as()->post(route('demo.choose'));
    Sandboxes::leave();

    // The Super Administrator finds it in their notifications in the showcase.
    $notices = DB::connection('pgsql')->table('public.notifications')->where('notifiable_id', $ray->id)->where('data', 'like', '%Ama Mensah%')->get();
    expect($notices)->toHaveCount(1);
    $data = json_decode((string) $notices[0]->data, true);
    expect($data['subject'])->toContain('Ama Mensah’s demo')->toContain('Approval needed')
        ->and($data['link'])->toBe(route('demo.showcase.open', $visitor, absolute: false));

    $showcase = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE);
    $showcase()->post(route('login'), ['email' => $ray->email, 'password' => 'Showcase-Pass-2026'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($ray);
    $showcase()->get(route('admin.users.index', ['show' => 'visitors']))->assertOk()->assertSee('Open their demo');

    // "Open their demo": a one-minute signed link, and this browser in the visitor's demo.
    $open = $showcase()->get(route('demo.showcase.open', $visitor));
    $open->assertRedirect()->assertCookie(Sandboxes::COOKIE, $key, false);
    $enter = (string) $open->headers->get('Location');
    $as()->get($enter)->assertRedirect(route('dashboard'));
    Sandboxes::use($key);
    $me = User::query()->where('role', Role::SuperAdmin)->firstOrFail();
    Sandboxes::leave();
    $this->assertAuthenticatedAs($me);
    $as()->get(route('dashboard'))->assertOk()->assertSee('as the Super Administrator')->assertDontSee('Delete my demo and account');

    // They approve the form, in the visitor's demo, as themselves; then go back.
    $as()->post(route('artefacts.approve', $form))->assertSessionHasNoErrors();
    $as()->post(route('demo.showcase.back'))->assertRedirect(route('login'))->assertCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE, false);

    Sandboxes::use($key);
    expect(Artefact::query()->findOrFail($form->id)->approved_by)->toBe($me->id);
    Sandboxes::leave();
    expect(Artefact::query()->findOrFail($form->id)->approved_by)->toBeNull();
});

it('lets nobody else into a visitor’s demo as the Super Administrator', function () {
    $ray = makeSuperAdmin();
    $key = register();
    $visitor = DemoVisitor::query()->email('ama@example.com')->firstOrFail();
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $key);

    // Without the signed link; or as the visitor's own Administrator.
    $as()->get(route('demo.showcase.enter', ['visitor' => $visitor, 'as' => $ray->email]))->assertNotFound();
    $as()->post(route('demo.sign-in'), ['role' => 'admin']);
    $as()->get(route('demo.showcase.open', $visitor))->assertNotFound();
    $this->assertAuthenticatedAs(DemoPeople::find('admin'));
});

it('gives every visitor a copy of the showcase’s work: one signs Zambia’s ODP as its Board Chairperson, and it stays waiting for everyone else', function () {
    $assessment = prepareInShowcase('zambia', makeSuperAdmin());
    $odp = $assessment->odp()->firstOrFail();
    $chair = DemoPeople::find('chair', Movement::query()->where('slug', 'zambia')->firstOrFail());

    $a = register('a@example.com');
    $b = register('b@example.com');
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $a);

    // Visitor A, as Zambia's Board Chairperson, finds the ODP and signs it.
    $as()->post(route('demo.sign-in'), ['role' => 'chair', 'movement' => 'zambia'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($chair);
    $as()->get(route('dashboard'))->assertOk();
    $as()->post(route('artefacts.sign', $odp), ['signature' => 'Ama Mensah', 'confirm' => '1'])->assertSessionHasNoErrors();

    // Signing out goes back to "Choose who to be"; as the Administrator, A sees it signed.
    $as()->post(route('logout'))->assertRedirect(route('login'));
    $as()->post(route('demo.sign-in'), ['role' => 'admin'])->assertRedirect(route('dashboard'));
    $as()->get(route('dashboard'))->assertOk();
    Sandboxes::use($a);
    expect(BoardSignature::query()->where('signed_by', $chair->id)->exists())->toBeTrue();

    // In the showcase and in visitor B's demo, it is still waiting to be signed.
    Sandboxes::use($b);
    expect(BoardSignature::query()->exists())->toBeFalse()
        ->and(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
    Sandboxes::leave();
    expect(BoardSignature::query()->exists())->toBeFalse();
});

/** The Super Administrator opens a movement's assessment in the showcase, as they would on its page. */
function openInShowcase(User $ray, string $slug): void
{
    test()->travel(1)->minutes();
    $showcase = fn () => test()->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE);
    $showcase()->post(route('login'), ['email' => $ray->email, 'password' => 'Showcase-Pass-2026'])->assertRedirect(route('dashboard'));
    $showcase()->post(route('assessments.store', Movement::query()->where('slug', $slug)->firstOrFail()), ['period_label' => 'Nov 2026', 'period_start' => '2026-11'])
        ->assertSessionHasNoErrors();
    $showcase()->post(route('logout'));
    test()->travel(1)->minutes();
}

/** The demo cookie a response sets, if any. */
function newDemoCookie($response): ?string
{
    return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Sandboxes::COOKIE)?->getValue();
}

it('brings the showcase’s new work into a visitor’s demo by itself, unless they have done work of their own', function () {
    $ray = makeSuperAdmin();
    $key = register();

    // The Super Administrator only signing in is not new work.
    $this->travel(1)->minutes();
    $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE)->post(route('login'), ['email' => $ray->email, 'password' => 'Showcase-Pass-2026']);
    $this->withUnencryptedCookie(Sandboxes::COOKIE, Sandboxes::SHOWCASE)->post(route('logout'));
    $response = $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'));
    $response->assertOk()->assertDontSee('New work was added to the showcase');
    expect(newDemoCookie($response))->toBeNull();

    // They open Kenya's assessment: on "Choose who to be", the visitor's demo has it, by itself.
    openInShowcase($ray, 'kenya');
    $response = $this->withUnencryptedCookie(Sandboxes::COOKIE, $key)->get(route('login'));
    $response->assertOk();
    $fresh = newDemoCookie($response);
    expect($fresh)->not->toBeNull()->not->toBe($key)
        ->and(Sandboxes::exists($key))->toBeFalse()
        ->and(DemoVisitor::query()->email('ama@example.com')->value('sandbox_key'))->toBe($fresh);
    Sandboxes::use($fresh);
    expect(Assessment::query()->count())->toBe(1)
        ->and(User::query()->where('email', DemoPeople::STAFF['admin']['email'])->value('name'))->toBe('Ama Mensah');
    Sandboxes::leave();

    // The visitor does work of their own (as the assessor, opens Zambia's assessment).
    $as = fn () => $this->withUnencryptedCookie(Sandboxes::COOKIE, $fresh);
    $as()->post(route('demo.sign-in'), ['role' => 'assessor']);
    $as()->post(route('assessments.store', Movement::query()->where('slug', 'zambia')->firstOrFail()), ['period_label' => 'Nov 2026', 'period_start' => '2026-11'])
        ->assertSessionHasNoErrors();
    $as()->post(route('demo.choose'));

    // New work in the showcase (Ghana) no longer replaces theirs: they are told, and choose.
    openInShowcase($ray, 'ghana');
    $response = $as()->get(route('login'));
    $response->assertOk()->assertSee('“Start over” to get it');
    expect(newDemoCookie($response))->toBeNull();
    Sandboxes::use($fresh);
    expect(Assessment::query()->count())->toBe(2);
    Sandboxes::leave();

    $new = demoCookie($as()->post(route('demo.start-over')));
    Sandboxes::use($new);
    expect(Assessment::query()->count())->toBe(2)
        ->and(Assessment::query()->whereHas('movement', fn ($q) => $q->where('slug', 'ghana'))->exists())->toBeTrue()
        ->and(Assessment::query()->whereHas('movement', fn ($q) => $q->where('slug', 'zambia'))->exists())->toBeFalse();
    Sandboxes::leave();

    // Deleting the visitor's demo leaves the showcase as it is.
    $this->withUnencryptedCookie(Sandboxes::COOKIE, $new)->delete(route('demo.destroy'))->assertRedirect(route('home'));
    expect(Assessment::query()->count())->toBe(2);
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
