<?php

namespace App\Queries;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;

/**
 * Stage 2, the weekly follow-up digest for one person: for each movement they follow up
 * (a General Secretary's own; an assessor's assigned ones), in follow-up and not paused:
 * the follow-up under way when it is open, past its agreed date, or due within two weeks;
 * and, once the three follow-ups are closed, the overall ODP review not yet written
 * (assessors). Tasks have no deadlines of their own (decided 12 Nov 2026). Movements with
 * nothing to say are left out.
 */
final class FollowUpDigest
{
    /**
     * @return list<array{movement: Movement, assessment: Assessment, follow_up: array{number: int, due_on: Carbon|null, open: bool, overdue: bool}|null, review_due: bool}>
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
            // Not while paused, nor once the cycle is closed.
            if ($movement->followUpPaused() || ($assessment = $movement->lastAssessmentDone()) === null || $assessment->isFollowUpClosed()) {
                continue;
            }
            $next = FollowUpSchedule::next($assessment);
            $open = $next !== null && $next['state'] === FollowUpSchedule::OPEN;
            $soon = $next !== null && $next['due_on'] !== null && $next['due_on']->lte(today()->addDays(14));

            $section = [
                'movement' => $movement,
                'assessment' => $assessment,
                'follow_up' => $next !== null && ($open || $soon) ? ['number' => $next['number'], 'due_on' => $next['due_on'], 'open' => $open, 'overdue' => $next['overdue']] : null,
                // The overall review, for the assessors, once the three follow-ups are closed and it is not written.
                'review_due' => $assessor && $next === null && ! $assessment->odpReview()->exists(),
            ];
            if ($section['follow_up'] === null && ! $section['review_due']) {
                continue;
            }
            $sections[] = $section;
        }

        return $sections;
    }
}
