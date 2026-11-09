<?php

use App\Models\Assessment;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\User;
use App\Models\Zone;
use App\Oha\WhyItMatters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Phase 5's quick wins: why each question matters, scores compared across assessments,
 * the Alliance's scores as a CSV, and the Administrators adding a National Movement.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

/** An earlier, approved assessment of Zambia: every weighted category at $share of its maximum. */
function earlierScoredAssessment(Movement $movement, float $share): Assessment
{
    $assessment = Assessment::factory()->withArtefacts()->create([
        'movement_id' => $movement->id, 'period_label' => 'Feb 2024', 'assessed_on' => '2024-02-01',
    ]);
    $assessment->artefacts()->where('kind', 'form')->update(['state' => 'approved']);
    foreach (Category::query()->whereNotNull('max_points')->get() as $category) {
        CategoryScore::factory()->create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'points' => round($category->max_points * $share, 2), 'max_points_at_scoring' => $category->max_points,
        ]);
    }

    return $assessment;
}

it('says why a gap matters, beside the gap and the evidence it calls for', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']))->assertOk()
        ->assertSee('Why it matters:')
        // Zambia's liabilities are above its assets: the warning says why that matters.
        ->assertSee(WhyItMatters::for('Q210'))
        ->assertSee(WhyItMatters::for('Q201'));
});

it('compares the category scores of a movement’s assessments, with the change', function () {
    earlierScoredAssessment($this->j->zambia, 0.5);
    $this->j->assessmentAt('form_approved');

    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('Scores over time')->assertSee('Feb 2024')->assertSee('Feb 2026')
        ->assertSee('50.0%')->assertSee('(up)', escape: false);

    // Its own Board Chairperson sees it too.
    $this->actingAs($this->j->chair)->get(route('movements.show', $this->j->zambia))->assertOk()->assertSee('Scores over time');
});

it('shows no comparison until a movement has two scored assessments', function () {
    $this->j->assessmentAt('form_approved');

    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->zambia))->assertOk()->assertDontSee('Scores over time');
});

it('exports the Alliance’s scores as a CSV, latest per movement or every assessment', function () {
    earlierScoredAssessment($this->j->zambia, 0.5);
    $this->j->assessmentAt('form_approved');

    $latest = $this->actingAs($this->j->otherStaff)->get(route('downloads.scores'))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim(ltrim($latest, "\xEF\xBB\xBF")))));
    $zambia = collect($rows)->filter(fn ($r) => $r[0] === 'Zambia YMCA')->values();

    expect($rows[0])->toContain('Movement', 'Financial Stability (%)', 'Score (%)', 'Band', 'ODP validated')
        ->and($zambia)->toHaveCount(1)
        ->and($zambia[0][4])->toBe('Feb 2026')
        // Every movement has a row; one not yet rated says so.
        ->and(count($rows) - 1)->toBe(Movement::query()->count())
        ->and(collect($rows)->firstWhere(0, 'Ghana YMCA'))->toContain('Not yet rated');

    $every = $this->actingAs($this->j->otherStaff)->get(route('downloads.scores', ['all' => 1]))->assertOk()->getContent();
    expect(substr_count($every, 'Zambia YMCA'))->toBe(2)->and($every)->not->toContain('Ghana YMCA');

    expect(DB::table('audit_events')->where('action', 'scores.exported')->count())->toBe(2);

    // Not for a movement's own users.
    $this->actingAs($this->j->chair)->get(route('downloads.scores'))->assertForbidden();
});

it('lets the Administrators add a National Movement, and correct it', function () {
    $zone = Zone::query()->where('code', 'southern')->firstOrFail();
    $associate = MembershipStatus::query()->where('code', 'associate')->firstOrFail();
    $details = ['name' => 'Botswana YMCA', 'country' => 'Botswana', 'city' => 'Gaborone', 'zone_id' => $zone->id, 'membership_status_id' => $associate->id, 'planned_on' => '2027-03'];

    $this->actingAs($this->j->admin)->get(route('movements.index'))->assertOk()->assertSee('Add a National Movement');
    $this->actingAs($this->j->admin)->get(route('admin.movements.create'))->assertOk();
    $this->actingAs($this->j->admin)->post(route('admin.movements.store'), $details)
        ->assertRedirect(route('movements.show', 'botswana'))->assertSessionHas('status');

    $botswana = Movement::query()->where('slug', 'botswana')->firstOrFail();
    expect($botswana->planned_assessment_label)->toBe('Mar 2027')
        ->and(DB::table('audit_events')->where('action', 'movement.created')->where('subject_id', $botswana->id)->exists())->toBeTrue();

    // It is a movement like the others: on the list, rateable, and ready for its users.
    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $botswana))->assertOk()->assertSee('Botswana YMCA')->assertSee('Mar 2027');
    $this->actingAs($this->j->admin)->get(route('admin.users.create'))->assertOk()->assertSee('Botswana YMCA');

    // The same name twice is refused.
    $this->actingAs($this->j->admin)->from(route('admin.movements.create'))->post(route('admin.movements.store'), $details)->assertSessionHasErrors('name');

    // Correcting it keeps its web address and records what changed.
    $this->actingAs($this->j->admin)->put(route('admin.movements.update', $botswana), ['city' => 'Francistown'] + $details)->assertRedirect(route('movements.show', 'botswana'));
    expect($botswana->refresh()->city)->toBe('Francistown')->and($botswana->slug)->toBe('botswana')
        ->and(DB::table('audit_events')->where('action', 'movement.updated')->value('payload'))->toContain('Francistown');
});

it('does not let staff or a movement’s users add or change a movement', function () {
    $details = ['name' => 'Botswana YMCA', 'country' => 'Botswana', 'city' => 'Gaborone', 'zone_id' => Zone::query()->value('id'), 'membership_status_id' => MembershipStatus::query()->value('id')];

    foreach ([$this->j->otherStaff, $this->j->chair, User::factory()->generalSecretary($this->j->zambia)->create()] as $user) {
        $this->actingAs($user)->get(route('admin.movements.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.movements.store'), $details)->assertForbidden();
        $this->actingAs($user)->get(route('admin.movements.edit', $this->j->zambia))->assertForbidden();
    }
    $this->actingAs($this->j->otherStaff)->get(route('movements.index'))->assertOk()->assertDontSee('Add a National Movement');
    expect(Movement::query()->where('slug', 'botswana')->exists())->toBeFalse();
});
