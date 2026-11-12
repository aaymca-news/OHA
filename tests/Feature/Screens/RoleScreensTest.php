<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SubmitForApproval;
use App\Models\Evidence;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Queries\MyWork;
use App\Support\Navigation;
use App\Support\SignedInDevices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Each role's menu and dashboard: the Administrators hand out the work and manage
 * the users; staff see their own work; a National Movement's Board Chairperson and
 * General Secretary see their movement at a glance.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda', 'title' => 'National General Secretary']);
    $this->menu = fn (User $user) => collect(app(Navigation::class)->for($user))->pluck('label')->all();
});

it('gives each role its own menu', function () {
    expect(($this->menu)($this->j->admin))->toBe(['Dashboard', 'My Work', 'National Movements', 'Assessments', 'Timelines', 'Journeys', 'Resources', 'Users & Roles'])
        ->and(($this->menu)($this->j->superAdmin))->toBe(['Dashboard', 'My Work', 'National Movements', 'Assessments', 'Timelines', 'Journeys', 'Resources', 'Users & Roles'])
        ->and(($this->menu)($this->j->assessor))->toBe(['Dashboard', 'My Work', 'National Movements', 'Timelines', 'Journeys', 'Resources'])
        ->and(($this->menu)($this->j->chair))->toBe(['Dashboard', 'My Work', 'Our Movement', 'Resources'])
        ->and(($this->menu)($this->ngs))->toBe(['Dashboard', 'Our Movement', 'Resources']);

    // AAYMCA staff see the menu in captioned groups; a movement's own short menu has none.
    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSeeInOrder(['Movements', 'National Movements', 'Timelines', 'Library', 'Resources', 'Administration', 'Users &amp; Roles'], false);
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()->assertDontSee('Administration')->assertDontSee('>Library<', false);
});

it('shows the movement’s own users its approved form, report and ODP; the ODP from its approval, to sign and to read', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');
    [$form, $report, $odp] = [$this->j->form($assessment), $this->j->report($assessment), $this->j->odp($assessment)];

    // Submitted, not yet approved: neither sees the ODP.
    expect($this->j->chair->can('view', $odp))->toBeFalse()->and($this->ngs->can('view', $odp))->toBeFalse();

    app(ApproveArtefact::class)->handle($odp, $this->j->admin);
    $odp->refresh();

    foreach ([$this->j->chair, $this->ngs] as $user) {
        expect($user->can('view', $form))->toBeTrue()
            ->and($user->can('view', $report))->toBeTrue()
            ->and($user->can('view', $odp))->toBeTrue()
            ->and($user->can('download', $odp->approvedVersion))->toBeTrue();
        $this->actingAs($user)->get(route('resources'))->assertOk()
            ->assertSee(route('downloads.document', $report->approvedVersion))
            ->assertSee(route('downloads.document', $odp->approvedVersion));
    }
    // Only the Board Chairperson signs.
    expect($this->j->chair->can('sign', $odp))->toBeTrue()->and($this->ngs->can('sign', $odp))->toBeFalse();

    $this->j->sign($odp);
    expect($this->ngs->can('view', $odp->refresh()))->toBeTrue();
});

it('tells the NGS when the form, report and ODP are approved, when the ODP is signed, and when follow-up begins', function () {
    $notice = WorkflowNotice::class;
    $assessment = $this->j->assessmentAt('report_approved');
    Notification::assertSentTo($this->ngs, $notice, fn ($n) => $n->subject === 'Now available: OHA form, Zambia YMCA');
    Notification::assertSentTo($this->ngs, $notice, fn ($n) => str_starts_with($n->subject, 'Now available: Health assessment report'));

    $this->j->uploadOdp($assessment, 'Zambia ODP, first version');
    app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    Notification::assertSentTo($this->ngs, $notice, fn ($n) => str_starts_with($n->subject, 'Now available: ODP') && str_contains($n->body, 'add your supporting documents'));
    // The Board Chairperson is told it is available; signing is optional, never asked for.
    Notification::assertSentTo($this->j->chair, $notice, fn ($n) => str_starts_with($n->subject, 'Now available: ODP') && str_contains($n->body, 'optional'));
    Notification::assertNotSentTo($this->j->chair, $notice, fn ($n) => str_starts_with($n->subject, 'Please sign'));

    $this->j->sign($this->j->odp($assessment));
    Notification::assertSentTo($this->ngs, $notice, fn ($n) => str_starts_with($n->subject, 'Validated by the board: ODP'));

    $this->j->close($assessment);
    Notification::assertSentTo($this->ngs, $notice, fn ($n) => $n->subject === 'Follow-up begins: Zambia YMCA');
});

it('lets the NGS add evidence once the ODP is approved, and from then on, signed included', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');
    expect($this->ngs->can('upload', [Evidence::class, $assessment]))->toBeFalse();
    $this->actingAs($this->ngs)->get(route('evidence.index', $this->j->zambia))->assertOk()
        ->assertDontSee('Add evidence')->assertSee('once AAYMCA has approved this assessment’s ODP');
    // AAYMCA staff add evidence at any stage.
    expect($this->j->assessor->can('upload', [Evidence::class, $assessment]))->toBeTrue();

    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    expect($this->ngs->can('upload', [Evidence::class, $assessment]))->toBeTrue();
    $this->actingAs($this->ngs)->get(route('evidence.index', $this->j->zambia))->assertOk()->assertSee('Add evidence');

    $this->j->sign($this->j->odp($assessment));
    expect($this->ngs->can('upload', [Evidence::class, $assessment->refresh()]))->toBeTrue();
});

it('lets only the Administrators hand out the work, on the Assessments page', function () {
    $this->actingAs($this->j->admin)->get(route('assessments.index'))->assertOk()
        ->assertSee('Who assesses each National Movement')->assertSee('Add, replace or remove assessors')->assertSee('Tendai Moyo');
    $this->actingAs($this->j->assessor)->get(route('assessments.index'))->assertForbidden();
    $this->actingAs($this->j->assessor)->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->j->assessor->id]])
        ->assertSessionHasErrors('action');
    expect($this->j->assessor->canAssess($this->j->ghana))->toBeFalse();
});

it('keeps an Administrator’s My Work to approvals and their own movements', function () {
    $this->j->assessmentAt('form_submitted');
    $groups = collect(app(MyWork::class)->for($this->j->admin))->keyBy('key');

    // Zambia is not theirs to assess, so its open gaps are not their work; its form is theirs to approve.
    expect($groups['gaps']['items'])->toBe([])
        ->and(collect($groups['approve']['items'])->pluck('artefact.assessment.movement.slug')->all())->toBe(['zambia']);
});

it('shows AAYMCA the region, and the movement its own dashboard', function () {
    $this->j->assessmentAt('odp_approved');

    $this->actingAs($this->j->assessor)->get(route('dashboard'))->assertOk()
        ->assertSee('Stage 1 across the region')->assertSee('How the ODPs are being carried out')->assertSee('By zone');

    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()
        ->assertSee('Welcome back')->assertSee('Your assessment cycle')->assertSee('Your ODP')
        ->assertSee('Your contacts at AAYMCA')->assertSee('Tendai Moyo')->assertDontSee('By zone')
        // Signing is optional: never a prompt on the dashboard (it is in My Work).
        ->assertDontSee('ready for your signature')->assertDontSee('Review and sign')->assertDontSee('in My Work');

    $this->actingAs($this->ngs)->get(route('dashboard'))->assertOk()
        ->assertSee('Welcome back')->assertDontSee('ready for your signature')->assertDontSee('Review and sign')->assertDontSee('Ghana YMCA');
});

it('keeps the job title of a National Movement’s users as the Administrators set it', function () {
    $this->actingAs($this->ngs)->get(route('profile.show'))->assertOk()->assertDontSee('name="title"', false)->assertSee('National General Secretary');
    $this->actingAs($this->ngs)->put(route('profile.update'), ['name' => 'Mwila K. Banda', 'title' => 'President'])->assertSessionHasNoErrors();
    expect($this->ngs->refresh()->only(['name', 'title']))->toBe(['name' => 'Mwila K. Banda', 'title' => 'National General Secretary']);

    $this->actingAs($this->j->assessor)->put(route('profile.update'), ['name' => 'Tendai Moyo', 'title' => 'Zonal Coordinator'])->assertSessionHasNoErrors();
    expect($this->j->assessor->refresh()->title)->toBe('Zonal Coordinator');
});

it('shows the text size slider and the devices signed in, on My profile', function () {
    $this->actingAs($this->ngs)->get(route('profile.show'))->assertOk()
        ->assertSee('type="range"', false)->assertSee('Text size')->assertSee('Where you are signed in');
});

it('signs out one other device, after checking the password, and leaves the rest', function () {
    $user = $this->j->assessor;
    DB::table('sessions')->insert([
        ['id' => 'laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'],
        ['id' => 'phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'],
    ]);
    $token = $user->getRememberToken();

    $this->actingAs($user)->get(route('security.show'))->assertSee('Chrome on Windows')->assertSee('Safari on iPhone')->assertDontSee('laptop');

    $this->actingAs($user)->delete(route('security.other-sessions'), ['password' => 'wrong', 'device' => SignedInDevices::key('phone')])->assertSessionHasErrors('password');
    $this->actingAs($user)->delete(route('security.other-sessions'), ['password' => 'password', 'device' => SignedInDevices::key('phone')])
        ->assertSessionHas('status', 'That device is signed out. It can sign in again with your password.');

    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe(['laptop'])
        ->and($user->refresh()->getRememberToken())->not->toBe($token);
});

it('keeps AAYMCA’s dashboard to analytics, the same for every staff role: their work stays in My Work', function () {
    $this->j->assessmentAt('form_submitted');   // the Administrators have the form to approve
    expect(app(MyWork::class)->actionableCount($this->j->admin))->toBeGreaterThan(0);

    foreach ([$this->j->assessor, $this->j->admin, $this->j->superAdmin] as $staff) {
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertSee('Movements rated')->assertSee('Stage 1 across the region')
            ->assertDontSee('Outstanding actions')->assertDontSee('Open My Work')->assertDontSee('Approve the OHA form');
    }
});
