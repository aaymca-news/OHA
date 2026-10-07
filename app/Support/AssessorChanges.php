<?php

namespace App\Support;

use App\Actions\Oha\AssignAssessors;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use Illuminate\Support\Collection;

/**
 * What happens whenever the staff assessing a movement change, however they were
 * changed (the movement's page, or a person's page in Users & Roles): the change is
 * in the movement's audit trail and in each of its assessments still in progress, and
 * those added and removed are told, in the dashboard and by email.
 *
 * Called inside the transaction that made the change; the notices go once it commits.
 */
final class AssessorChanges
{
    /**
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $removed
     * @param  bool  $tellAdded  false when they are told another way (a new user's invitation)
     */
    public static function record(User $by, Movement $movement, Collection $added, Collection $removed, bool $tellAdded = true): void
    {
        if ($added->isEmpty() && $removed->isEmpty()) {
            return;
        }

        $payload = [
            'added' => $added->pluck('id')->values()->all(),
            'removed' => $removed->pluck('id')->values()->all(),
            'added_names' => $added->pluck('name')->values()->all(),
            'removed_names' => $removed->pluck('name')->values()->all(),
            'now' => $movement->assessors()->orderBy('name')->pluck('name')->all(),
        ];

        Audit::record($by, 'movement.assessors_changed', $movement, payload: $payload);
        foreach (AssignAssessors::inProgress($movement) as $assessment) {
            Audit::record($by, 'assessment.assessors_changed', $movement, $assessment, payload: $payload);
        }

        if ($tellAdded) {
            Notify::send($added, new WorkflowNotice(
                "You are assigned to assess {$movement->name}",
                "{$by->name} assigned you to assess {$movement->name}. It is now in My Work: open its assessment there, or from the movement’s page, and upload its OHA form.",
                '/movements/'.$movement->slug, 'action',
            ), except: $by);
        }
        Notify::send($removed, new WorkflowNotice(
            "You no longer assess {$movement->name}",
            "{$by->name} reassigned the assessment of {$movement->name}".($added->isNotEmpty() ? ' to '.$added->pluck('name')->join(', ', ' and ') : '').'.',
            '/movements/'.$movement->slug, 'info',
        ), except: $by);
    }
}
