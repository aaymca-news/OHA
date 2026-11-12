<?php

namespace App\Queries;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Enums\HolderRole;
use App\Enums\Role;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Models\WorkItem;
use App\Oha\FollowUpSchedule;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One queue for every role: the same entry point, only the groups differ.
 *
 *   start     assessors: their movements with no assessment under way, to open
 *   started   assessors: their movements whose assessment has started; actionable when it is with them
 *   done      assessors: their movements whose assessment is done (closed), follow-ups under way
 *   approve   Administrators: everything awaiting approval (what they submitted themselves is shown locked, with why)
 *   close     Administrators: assessments whose ODP is approved, ready to close (on the movements
 *             they do not assess themselves; those are in "started")
 *   sign      the Board Chairperson: the approved ODP they may sign (optional: never a reminder)
 *   gaps      forms on your movements that went forward with missing information still open
 *
 * Each entry is one document, with its due date from v_work_items where it is on
 * the assessment's main line of work.
 */
final class MyWork
{
    /** Groups that inform rather than wait on someone: never on the badge, the banner or "waiting on you". */
    public const QUIET = ['sign', 'gaps'];

    /**
     * @return list<array{key: string, items: list<array{artefact: Artefact|null, movement?: Movement, opened?: true, started?: true, done?: Assessment|null, follow_up?: array<string, mixed>|null, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}>
     */
    public function for(User $user): array
    {
        $groups = [];
        $assigned = $user->assignedMovements()->pluck('movements.id')->all();

        if ($user->isAdmin()) {
            $groups[] = $this->fromArtefacts('approve', $this->artefacts()
                ->where('state', ArtefactState::PendingApproval)->get(),
                __('oha.holder.approver'), fn (Artefact $a) => Gate::forUser($user)->inspect('approve', $a));
            // Approved ODPs to close (Stage 1 done), on movements someone else assesses.
            $groups[] = $this->fromWork('close', $this->work(HolderRole::Closer)->whereNotIn('movement_id', $assigned)->get(),
                fn (Artefact $a) => Gate::forUser($user)->inspect('close', $a->assessment));
        }

        // Only the Board Chairperson signs (optional, no target date); the National General Secretary has nothing to sign.
        if ($user->role === Role::Board) {
            $toSign = $this->artefacts()
                ->where('kind', ArtefactKind::Odp)
                ->where('state', ArtefactState::Approved)->whereDoesntHave('signature')
                ->whereHas('assessment', fn (Builder $q) => $q->where('movement_id', $user->movement_id))
                ->get();
            $groups[] = ['key' => 'sign', 'items' => $toSign->map(fn (Artefact $a) => $this->entry($a, __('oha.holder.board'), null,
                fn (Artefact $a) => Gate::forUser($user)->inspect('sign', $a)))->values()->all()];
        }

        // The movements they assess, always these four (who did what is in each assessment's audit trail):
        if ($user->isAssessor()) {
            // assigned, with no assessment under way: theirs to open;
            $groups[] = ['key' => 'start', 'items' => $this->toStart($user, $assigned)];
            // opened and not yet closed, with where each stands (theirs to act on when it is with them, or ready to close);
            $groups[] = ['key' => 'started', 'items' => $this->started($assigned)];
            // closed, its follow-ups under way;
            $groups[] = ['key' => 'done', 'items' => $this->done($user)];
            // and forms that went forward with information missing. Only assessments under way:
            // once the assessment is closed, it leaves this list.
            $groups[] = $this->fromWork('gaps', $this->withOpenGaps($assigned), fn () => null);
        }

        return $groups;
    }

    /**
     * Actionable items only: the count on the sidebar badge.
     */
    public function actionableCount(User $user): int
    {
        return collect($this->for($user))
            ->whereNotIn('key', self::QUIET)
            ->sum(fn (array $group) => count(array_filter($group['items'], fn ($i) => $i['actionable'])));
    }

    /**
     * The movements this person is assigned to assess where nothing has started: no
     * assessment under way (never assessed, or the next one is due), or one opened with
     * nothing uploaded yet. Theirs to open, or to begin with the OHA form.
     *
     * @param  list<int>  $assigned
     * @return list<array{artefact: Artefact|null, movement: Movement, opened?: true, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>
     */
    private function toStart(User $user, array $assigned): array
    {
        $toOpen = $this->idle($user)
            ->reject(fn (Movement $m) => $this->followingUp($m))
            ->map(function (Movement $m) use ($user) {
                $response = Gate::forUser($user)->inspect('openAssessment', $m);

                return [
                    'artefact' => null,
                    'movement' => $m,
                    'holder' => __('oha.holder.assessor'),
                    'days_left' => null,
                    'actionable' => $response->allowed(),
                    'blocked' => $response->denied() ? $response->message() : null,
                ];
            });

        $opened = $this->work(null)->whereIn('movement_id', $assigned)->get()
            ->filter(fn (WorkItem $w) => $this->notBegun($w))
            ->map(fn (WorkItem $w) => [
                'artefact' => $w->artefact,
                'movement' => $w->artefact->assessment->movement,
                'opened' => true,
                'holder' => $w->holder_role->label(),
                'days_left' => $w->days_left,
                'actionable' => true,
                'blocked' => null,
            ]);

        return $toOpen->concat($opened)->sortBy(fn (array $e) => $e['movement']->name)->values()->all();
    }

    /**
     * Opened, but nothing done yet: the OHA form, the first step, has never been uploaded.
     */
    private function notBegun(WorkItem $w): bool
    {
        return $w->artefact->kind === ArtefactKind::Form && $w->artefact->state === ArtefactState::NotStarted;
    }

    /**
     * The movements this person assesses with no assessment under way.
     *
     * @return Collection<int, Movement>
     */
    private function idle(User $user): Collection
    {
        return $user->assignedMovements()
            ->whereDoesntHave('assessments', fn (Builder $a) => $a->underWay())
            ->with('status')->orderBy('name')->get();
    }

    /**
     * Its assessment is done (closed) and its cycle is not closed yet: the follow-ups are
     * what is under way, not a new assessment (which waits for the cycle to close).
     */
    private function followingUp(Movement $m): bool
    {
        $last = $m->lastAssessmentDone();

        return $last !== null && ! $last->isFollowUpClosed();
    }

    /**
     * The movements this person assesses whose assessment is done (Stage 1: closed),
     * with its follow-ups under way (Stage 2). When the follow-up under way is open (its
     * meeting to hold, record and close) or past its agreed date, it is theirs to act on,
     * and counts on the badge.
     *
     * @return list<array{artefact: null, movement: Movement, done: Assessment, follow_up: array<string, mixed>|null, holder: string, days_left: null, actionable: bool, blocked: null}>
     */
    private function done(User $user): array
    {
        return $this->idle($user)
            ->filter(fn (Movement $m) => $this->followingUp($m))
            ->map(function (Movement $m) {
                $done = $m->lastAssessmentDone();
                $next = $done === null || $done->isFollowUpClosed() ? null : FollowUpSchedule::next($done);

                return [
                    'artefact' => null,
                    'movement' => $m,
                    'done' => $done,
                    'follow_up' => $next,
                    'holder' => __('oha.holder.assessor'),
                    'days_left' => null,
                    'actionable' => $next !== null && ($next['state'] === FollowUpSchedule::OPEN || $next['overdue']),
                    'blocked' => null,
                ];
            })
            ->filter(fn (array $entry) => $entry['done'] !== null)
            ->values()->all();
    }

    /**
     * The ongoing assessments (opened, not yet closed) of the movements this person
     * assesses, with where each stands. One waiting on the assessor (to prepare, sent back,
     * or approved and ready to close) is theirs to act on, so it counts on the badge; the
     * rest are with someone else.
     *
     * @param  list<int>  $assigned
     * @return list<array{artefact: Artefact, movement: Movement, started: true, ready: bool, holder: string, days_left: int|null, actionable: bool, blocked: null}>
     */
    private function started(array $assigned): array
    {
        return $this->work(null)
            ->whereIn('movement_id', $assigned)
            ->get()
            ->reject(fn (WorkItem $w) => $this->notBegun($w)) // nothing done yet: "To be started"
            ->sortBy(fn (WorkItem $w) => $w->artefact->assessment->movement->name)
            ->map(fn (WorkItem $w) => [
                'artefact' => $w->artefact,
                'movement' => $w->artefact->assessment->movement,
                'started' => true,
                'ready' => $w->holder_role === HolderRole::Closer,
                'holder' => $w->holder_role->label(),
                'days_left' => $w->days_left,
                'actionable' => in_array($w->holder_role, [HolderRole::Assessor, HolderRole::Closer], true),
                'blocked' => null,
            ])->values()->all();
    }

    /**
     * @return Builder<WorkItem>
     */
    private function work(?HolderRole $holder): Builder
    {
        return WorkItem::query()->with(['artefact.assessment.movement', 'artefact.submitter'])
            ->when($holder !== null, fn (Builder $q) => $q->where('holder_role', $holder?->value))
            ->orderBy('due_on')->orderBy('assessment_id');
    }

    /**
     * @return Builder<Artefact>
     */
    private function artefacts(): Builder
    {
        return Artefact::query()->with(['assessment.movement', 'assessment.workItem', 'submitter'])
            ->orderBy('submitted_at')->orderBy('approved_at')->orderBy('id');
    }

    /**
     * Open assessments whose current form went forward with gaps still open.
     *
     * @param  list<int>|null  $movementIds  null for every movement
     * @return Collection<int, WorkItem>
     */
    private function withOpenGaps(?array $movementIds): Collection
    {
        return $this->work(null)
            ->when($movementIds !== null, fn (Builder $q) => $q->whereIn('movement_id', $movementIds))
            ->whereIn('assessment_id', Artefact::query()->select('assessment_id')
                ->where('kind', ArtefactKind::Form)
                ->whereNotIn('state', [ArtefactState::NotStarted->value, ArtefactState::RulesFailed->value])
                ->whereHas('currentUpload.findings', fn (Builder $f) => $f->where('severity', FindingSeverity::Missing->value)->whereNull('resolved_at')))
            ->get();
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  (callable(Artefact): (Response|null))|null  $decide
     * @return array{key: string, items: list<array{artefact: Artefact, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}
     */
    private function fromWork(string $key, Collection $items, ?callable $decide = null): array
    {
        return ['key' => $key, 'items' => $items->map(fn (WorkItem $w) => $this->entry($w->artefact, $w->holder_role->label(), $w->days_left, $decide))->values()->all()];
    }

    /**
     * @param  Collection<int, Artefact>  $artefacts
     * @param  callable(Artefact): (Response|null)  $decide
     * @return array{key: string, items: list<array{artefact: Artefact, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}
     */
    private function fromArtefacts(string $key, Collection $artefacts, string $holder, callable $decide): array
    {
        return ['key' => $key, 'items' => $artefacts->map(function (Artefact $a) use ($holder, $decide) {
            // The ODP is on the main line of work, so it carries a due date; the report and form do not.
            $work = $a->assessment->workItem;
            $days = $work !== null && $work->artefact_id === $a->id ? $work->days_left : null;

            return $this->entry($a, $holder, $days, $decide);
        })->values()->all()];
    }

    /**
     * @param  (callable(Artefact): (Response|null))|null  $decide
     * @return array{artefact: Artefact, holder: string, days_left: int|null, actionable: bool, blocked: string|null}
     */
    private function entry(Artefact $artefact, string $holder, ?int $days, ?callable $decide): array
    {
        $response = $decide !== null ? $decide($artefact) : null;

        return [
            'artefact' => $artefact,
            'holder' => $holder,
            'days_left' => $days,
            'actionable' => $decide === null || ($response !== null && $response->allowed()),
            'blocked' => $response !== null && $response->denied() ? $response->message() : null,
        ];
    }
}
