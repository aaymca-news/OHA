<?php

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\Role;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\Movement;
use App\Models\TimelineItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The rules below hold even for a script or a direct SQL session, because the
 * database enforces them, not only the application.
 */

/**
 * Run a statement in its own savepoint. PostgreSQL aborts the whole test
 * transaction after a failed statement, so every expected failure is contained.
 */
function attempt(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

it('accepts only the four roles', function () {
    foreach (['oha_lead', 'oha_asst_lead', 'gs', 'validator'] as $gone) {
        expect(attempt(fn () => DB::table('users')->insert(['name' => 'X', 'email' => "{$gone}@example.org", 'password' => 'x', 'role' => $gone])))
            ->toThrow(QueryException::class, 'users_role_valid');
    }

    expect(User::factory()->superAdmin()->create()->role)->toBe(Role::SuperAdmin)
        ->and(User::factory()->admin()->create()->role)->toBe(Role::Admin);
});

it('allows only one active Board Chairperson per movement', function () {
    $movement = Movement::factory()->create();
    User::factory()->chair($movement)->create();

    expect(attempt(fn () => User::factory()->chair($movement)->create()))->toThrow(QueryException::class)
        ->and(User::factory()->chair($movement)->inactive()->create()->role)->toBe(Role::Board)
        ->and(User::factory()->chair()->create()->role)->toBe(Role::Board);
});

it('requires a Board Chairperson, and only a Board Chairperson, to belong to a movement', function () {
    expect(attempt(fn () => User::factory()->create(['role' => Role::Board])))->toThrow(QueryException::class)
        ->and(attempt(fn () => User::factory()->create(['movement_id' => Movement::factory()])))->toThrow(QueryException::class);
});

it('rejects points outside a category range', function () {
    $assessment = Assessment::factory()->create();
    $diversity = Category::query()->where('code', 'diversity')->firstOrFail();

    expect(attempt(fn () => CategoryScore::factory()->create([
        'assessment_id' => $assessment->id,
        'category_id' => $diversity->id,
        'points' => 5,
        'max_points_at_scoring' => 4,
    ])))->toThrow(QueryException::class);
});

it('refuses to edit a score once it is recorded', function () {
    $assessment = Assessment::factory()->create();
    $governance = Category::query()->where('code', 'governance')->firstOrFail();
    CategoryScore::factory()->create([
        'assessment_id' => $assessment->id,
        'category_id' => $governance->id,
        'points' => 9,
        'max_points_at_scoring' => 12,
    ]);

    expect(attempt(fn () => DB::table('category_scores')->where('assessment_id', $assessment->id)->update(['points' => 12])))
        ->toThrow(QueryException::class, 'frozen');
});

it('rejects a state that does not belong to the artefact kind', function () {
    expect(attempt(fn () => Artefact::factory()->kind(ArtefactKind::Form)->inState(ArtefactState::Drafted)->create()))
        ->toThrow(QueryException::class)
        ->and(attempt(fn () => Artefact::factory()->kind(ArtefactKind::Report)->inState(ArtefactState::RulesFailed)->create()))
        ->toThrow(QueryException::class);
});

it('keeps the audit trail append-only', function () {
    $event = AuditEvent::factory()->create();

    expect(attempt(fn () => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(attempt(fn () => DB::table('audit_events')->where('id', $event->id)->delete()))
        ->toThrow(QueryException::class, 'append-only')
        ->and(attempt(fn () => DB::statement('TRUNCATE audit_events')))
        ->toThrow(QueryException::class, 'append-only');
});

it('stores a gate deadline only when a person sets it, and never a gate completion date', function () {
    $assessment = Assessment::factory()->create();

    $step = TimelineItem::factory()->for($assessment)->create(['done_on' => now()->toDateString()]);
    TimelineItem::factory()->for($assessment)->gateDeadline('form', '2026-12-01')->create();

    expect($step->done_on)->not->toBeNull()
        ->and(attempt(fn () => TimelineItem::factory()->for($assessment)->gateDeadline('form', '2026-12-15')->create()))
        ->toThrow(QueryException::class)
        ->and(attempt(fn () => TimelineItem::factory()->for($assessment)->gateDeadline('report', '2026-12-15')->create(['done_on' => '2026-12-10'])))
        ->toThrow(QueryException::class);
});
