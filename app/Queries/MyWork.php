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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One queue for every role: the same entry point, only the groups differ.
 *
 *   start     assessors: their movements with no assessment under way, to open
 *   started   assessors: their movements whose assessment has started; actionable when it is with them
 *   done      assessors: their movements whose assessment is done (ODP signed), follow-ups under way
 *   approve   Administrators: everything awaiting approval (what they submitted themselves is shown locked, with why)
 *   sign      the Board Chairperson: the approved ODP awaiting their signature
 *   gaps      forms on your movements that went forward with missing information still open
 *
 * Each entry is one document, with its due date from v_work_items where it is on
 * the assessment's main line of work.
 */
final class MyWork
{
    /**
     * @return list<array{key: string, items: list<array{artefact: Artefact|null, movement?: Movement, opened?: true, started?: true, done?: Assessment|null, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}>
     */
    public function for(User $user): array
    {
        $groups = [];
        $assigned = $user->assignedMovements()->pluck('movements.id')->all();

        if ($user->isAdmin()) {
            $groups[] = $this->fromArtefacts('approve', $this->artefacts()
                ->where('state', ArtefactState::PendingApproval)->get(),
                __('oha.holder.approver'), fn (Artefact $a) => Gate::forUser($user)->inspect('approve', $a));
        }

        // Only the Board Chairperson signs; the National General Secretary has nothing to sign.
        if ($user->role === Role::Board) {
            $groups[] = $this->fromArtefacts('sign', $this->artefacts()
                ->where('kind', ArtefactKind::Odp)
                ->where('state', ArtefactState::Approved)->whereDoesntHave('signature')
                ->whereHas('assessment', fn (Builder $q) => $q->where('movement_id', $user->movement_id))
                ->get(), __('oha.holder.board'), fn (Artefact $a) => Gate::forUser($user)->inspect('sign', $a));
        }

        // The movements they assess, always these four (who did what is in each assessment's audit trail):
        if ($user->isAssessor()) {
            // assigned, with no assessment under way: theirs to open;
            $groups[] = ['key' => 'start', 'items' => $this->toStart($user, $assigned)];
            // opened and not yet signed by the board, with where each stands (theirs to act on when it is with them);
            $groups[] = ['key' => 'started', 'items' => $this->started($assigned)];
            // signed by the board, its follow-ups under way;
            $groups[] = ['key' => 'done', 'items' => $this->done($user)];
            // and forms that went forward with information missing. Only assessments under way:
            // once the ODP is signed, the assessment leaves this list.
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
            ->whereIn('key', ['start', 'started', 'approve', 'sign'])
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
     * Its assessment is done (ODP signed) and the next one is not yet due: the
     * follow-ups are what is under way, not a new assessment.
     */
    private function followingUp(Movement $m): bool
    {
        $next = $m->status?->next_assessment_on;

        return (bool) $m->status?->has_odp && $next !== null && $next->isFuture();
    }

    /**
     * The movements this person assesses whose assessment is done (Stage 1: ODP signed),
     * with its follow-ups under way, until the next assessment is due. For reference.
     *
     * @return list<array{artefact: null, movement: Movement, done: Assessment, holder: string, days_left: null, actionable: bool, blocked: null}>
     */
    private function done(User $user): array
    {
        return $this->idle($user)
            ->filter(fn (Movement $m) => $this->followingUp($m))
            ->map(fn (Movement $m) => [
                'artefact' => null,
                'movement' => $m,
                'done' => $m->lastAssessmentDone(),
                'holder' => __('oha.holder.assessor'),
                'days_left' => null,
                'actionable' => false,
                'blocked' => null,
            ])
            ->filter(fn (array $entry) => $entry['done'] !== null)
            ->values()->all();
    }

    /**
     * The ongoing assessments (opened, ODP not yet signed) of the movements this person
     * assesses, with where each stands. One waiting on the assessor (to prepare, or sent
     * back) is theirs to act on, so it counts on the badge; the rest are with someone else.
     *
     * @param  list<int>  $assigned
     * @return list<array{artefact: Artefact, movement: Movement, started: true, holder: string, days_left: int|null, actionable: bool, blocked: null}>
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
                'holder' => $w->holder_role->label(),
                'days_left' => $w->days_left,
                'actionable' => $w->holder_role === HolderRole::Assessor,
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
