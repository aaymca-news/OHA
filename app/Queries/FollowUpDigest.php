<?php

namespace App\Queries;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\OdpTask;
use App\Models\User;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stage 2, the weekly follow-up digest for one person: for each movement they follow up
 * (a General Secretary's own; an assessor's assigned ones), in follow-up and not paused:
 * the ODP tasks due in the next 30 days and overdue, the tasks waiting for an assessor's
 * confirmation (assessors only), a follow-up meeting due within two weeks or overdue, the
 * overall ODP review not yet written (assessors, from 90 days before the next assessment),
 * and the next assessment when it is 90 days away or less. Movements with nothing to say
 * are left out.
 */
final class FollowUpDigest
{
    /**
     * @return list<array{movement: Movement, assessment: Assessment, overdue: Collection<int, OdpTask>, due_soon: Collection<int, OdpTask>, waiting: Collection<int, OdpTask>, meeting: array{number: int, due_on: Carbon}|null, reassess_on: Carbon|null, review_due: bool}>
     */
    public function for(User $user): array
    {
        if (! $user->active) {
            return [];
        }
        $movements = match (true) {
            $user->role === Role::Ngs && $user->movement_id !== null => Movement::query()->whereKey($user->movement_id)->get(),
            $user->isAssessor() => $user->assignedMovements()->orderBy('name')->get(),
            default => collect(),
        };
        $assessor = $user->isAssessor();

        $sections = [];
        foreach ($movements as $movement) {
            /** @var Movement $movement */
            // Not while paused, nor once the cycle is closed (the reassessment reminders carry on).
            if ($movement->followUpPaused() || ($assessment = $movement->lastAssessmentDone()) === null || $assessment->isFollowUpClosed()) {
                continue;
            }
            $open = $assessment->odpTasks()->where('done', false)->get();
            $soon = today()->addDays((int) config('oha.due_soon_days.odp_tasks'));
            $next = FollowUpSchedule::next($assessment);
            $reassess = MovementStatus::query()->whereKey($movement->id)->value('next_assessment_on');
            $reassessOn = $reassess !== null ? Carbon::parse($reassess) : null;

            $section = [
                'movement' => $movement,
                'assessment' => $assessment,
                'overdue' => $open->filter(fn (OdpTask $t) => $t->due_on !== null && $t->due_on->lt(today()))->values(),
                'due_soon' => $open->filter(fn (OdpTask $t) => $t->due_on !== null && $t->due_on->gte(today()) && $t->due_on->lte($soon))->values(),
                'waiting' => $assessor ? $open->filter(fn (OdpTask $t) => $t->awaitsConfirmation())->values() : collect(),
                'meeting' => $next !== null && $next['due_on']->lte(today()->addDays(14)) ? ['number' => $next['number'], 'due_on' => $next['due_on']] : null,
                'reassess_on' => $reassessOn !== null && $reassessOn->lte(today()->addDays(90)) ? $reassessOn : null,
                // The overall review, for the assessors, once the next assessment is near and it is not written.
                'review_due' => $assessor && $reassessOn !== null && $reassessOn->lte(today()->addDays(90)) && ! $assessment->odpReview()->exists(),
            ];
            if ($section['overdue']->isEmpty() && $section['due_soon']->isEmpty() && $section['waiting']->isEmpty()
                && $section['meeting'] === null && $section['reassess_on'] === null && ! $section['review_due']) {
                continue;
            }
            $sections[] = $section;
        }

        return $sections;
    }
}
