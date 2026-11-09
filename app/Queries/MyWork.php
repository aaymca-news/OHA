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
 *   mine      assessors: work waiting on them, including anything sent back
 *   start     assessors: their movements with no assessment under way, to open
 *   started   assessors: their movements whose assessment has started (Administrators: every one)
 *   done      assessors: their movements whose assessment is done (ODP signed), follow-ups under way
 *   approve   Administrators: everything awaiting approval (what they submitted themselves is shown locked, with why)
 *   sign      the Board Chairperson: the approved ODP awaiting their signature
 *   gaps      forms on your movements that went forward with missing information still open
 *   watching  submitted by you, or on your movements, and now with someone else
 *
 * Each entry is one document, with its due date from v_work_items where it is on
 * the assessment's main line of work.
 */
final class MyWork
{
    /**
     * @return list<array{key: string, items: list<array{artefact: Artefact|null, movement?: Movement, started?: true, done?: Assessment|null, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}>
     */
    public function for(User $user): array
    {
        $groups = [];
        $assigned = $user->assignedMovements()->pluck('movements.id')->all();

        if ($user->isAssessor()) {
            $groups[] = $this->fromWork('mine', $this->work(HolderRole::Assessor)->whereIn('movement_id', $assigned)->get());

            // Movements given to them with no assessment under way: theirs to open.
            $toStart = $this->toStart($user);
            if ($toStart !== []) {
                $groups[] = ['key' => 'start', 'items' => $toStart];
            }

            // …and those whose assessment has started, with where each one stands.
            $started = $this->started($user, $assigned);
            if ($started !== []) {
                $groups[] = ['key' => 'started', 'items' => $started];
            }

            // …and those whose assessment is done (ODP signed), its follow-ups under way.
            $done = $this->done($user);
            if ($done !== []) {
                $groups[] = ['key' => 'done', 'items' => $done];
            }
        }

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

        if ($user->isAssessor()) {
            // Only on the movements they assess: My Work is their own work. (Administrators
            // see every form waiting on them under "approve".)
            $gaps = $this->withOpenGaps($assigned);
            if ($gaps->isNotEmpty()) {
                $groups[] = $this->fromWork('gaps', $gaps, fn () => null);
            }

            // Administrators already see everything awaiting approval under "approve".
            $watching = $this->work(null)
                ->where('holder_role', '!=', HolderRole::Assessor->value)
                ->when($user->isAdmin(), fn (Builder $q) => $q->where('holder_role', '!=', HolderRole::Approver->value))
                ->where(fn (Builder $q) => $q->whereIn('movement_id', $assigned)
                    ->orWhereHas('artefact', fn (Builder $a) => $a->where('submitted_by', $user->id)))
                ->get();
            if ($watching->isNotEmpty()) {
                $groups[] = $this->fromWork('watching', $watching, fn () => null);
            }
        }

        return $groups;
    }

    /**
     * Actionable items only: the count on the sidebar badge.
     */
    public function actionableCount(User $user): int
    {
        return collect($this->for($user))
            ->whereIn('key', ['mine', 'start', 'approve', 'sign'])
            ->sum(fn (array $group) => count(array_filter($group['items'], fn ($i) => $i['actionable'])));
    }

    /**
     * The movements this person is assigned to assess that have no assessment under way:
     * never assessed, or their last assessment is complete. Theirs to open.
     *
     * @return list<array{artefact: null, movement: Movement, holder: string, days_left: null, actionable: bool, blocked: string|null}>
     */
    private function toStart(User $user): array
    {
        return $this->idle($user)
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
            })->values()->all();
    }

    /**
     * The movements this person assesses with no assessment under way.
     *
     * @return Collection<int, Movement>
     */
    private function idle(User $user): Collection
    {
        return $user->assignedMovements()
            ->whereDoesntHave('assessments', fn (Builder $a) => $a->whereHas('workItem'))
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
     * The movements whose assessment has started (opened, its ODP not yet signed): the
     * ones this person assesses, or every one for the Administrators. For reference, so
     * never counted on the badge; the work itself is in the other groups.
     *
     * @param  list<int>  $assigned
     * @return list<array{artefact: Artefact, movement: Movement, started: true, holder: string, days_left: int|null, actionable: bool, blocked: null}>
     */
    private function started(User $user, array $assigned): array
    {
        return $this->work(null)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->whereIn('movement_id', $assigned))
            ->get()
            ->sortBy(fn (WorkItem $w) => $w->artefact->assessment->movement->name)
            ->map(fn (WorkItem $w) => [
                'artefact' => $w->artefact,
                'movement' => $w->artefact->assessment->movement,
                'started' => true,
                'holder' => $w->holder_role->label(),
                'days_left' => $w->days_left,
                'actionable' => false,
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
                ->where('kind', 'form')
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
