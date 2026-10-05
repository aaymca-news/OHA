<?php

namespace App\Queries;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Enums\HolderRole;
use App\Models\Artefact;
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
 *   approve   Administrators: everything awaiting approval (what they submitted themselves is shown locked, with why)
 *   sign      the Board Chairperson: the approved ODP awaiting their signature
 *   gaps      forms that went forward with missing information still open
 *   watching  submitted by you, or on your movements, and now with someone else
 *
 * Each entry is one document, with its due date from v_work_items where it is on
 * the assessment's main line of work.
 */
final class MyWork
{
    /**
     * @return list<array{key: string, items: list<array{artefact: Artefact, holder: string, days_left: int|null, actionable: bool, blocked: string|null}>}>
     */
    public function for(User $user): array
    {
        $groups = [];
        $assigned = $user->assignedMovements()->pluck('movements.id')->all();

        if ($user->isAssessor()) {
            $groups[] = $this->fromWork('mine', $this->work(HolderRole::Assessor)->whereIn('movement_id', $assigned)->get());
        }

        if ($user->isAdmin()) {
            $groups[] = $this->fromArtefacts('approve', $this->artefacts()
                ->where('state', ArtefactState::PendingApproval)->get(),
                __('oha.holder.approver'), fn (Artefact $a) => Gate::forUser($user)->inspect('approve', $a));
        }

        if (! $user->isSecretariat()) {
            $groups[] = $this->fromArtefacts('sign', $this->artefacts()
                ->where('kind', ArtefactKind::Odp)
                ->where('state', ArtefactState::Approved)->whereDoesntHave('signature')
                ->whereHas('assessment', fn (Builder $q) => $q->where('movement_id', $user->movement_id))
                ->get(), __('oha.holder.board'), fn (Artefact $a) => Gate::forUser($user)->inspect('sign', $a));
        }

        if ($user->isAssessor()) {
            $gaps = $this->withOpenGaps($user->oversees() ? null : $assigned);
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
            ->whereIn('key', ['mine', 'approve', 'sign'])
            ->sum(fn (array $group) => count(array_filter($group['items'], fn ($i) => $i['actionable'])));
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
