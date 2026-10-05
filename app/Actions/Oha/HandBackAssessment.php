<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * An assessor closes their part in an assessment: they stop working on it and are
 * taken off the movement. The assessment stays where it is, for whoever is assigned
 * next. The Administrators are told, and asked to assign an assessor.
 */
final class HandBackAssessment
{
    use EnforcesPolicy;

    public function handle(Assessment $assessment, User $assessor, string $reason): Assessment
    {
        $this->ensure($assessor, 'handBack', $assessment);

        if (mb_strlen(trim($reason)) < 3) {
            throw new WorkflowRuleBroken('Say why you are stopping: the Administrators read this when they assign someone new.');
        }

        return DB::transaction(function () use ($assessment, $assessor, $reason): Assessment {
            $movement = $assessment->movement;
            $movement->assessors()->detach($assessor->id);
            $remaining = $movement->assessors()->where('active', true)->orderBy('name')->pluck('name')->all();

            Audit::record($assessor, 'assessment.handed_back', $assessment, $assessment, payload: [
                'reason' => trim($reason),
                'remaining_assessors' => $remaining,
            ]);
            Audit::record($assessor, 'movement.assessors_changed', $movement, payload: [
                'added' => [], 'removed' => [$assessor->id],
                'added_names' => [], 'removed_names' => [$assessor->name],
                'now' => $remaining, 'handed_back' => true, 'reason' => trim($reason),
            ]);

            $admins = User::query()->whereIn('role', [Role::Admin, Role::SuperAdmin])->where('active', true)->get();
            Notify::send($admins, new WorkflowNotice(
                "Assessor needed: {$movement->name}",
                "{$assessor->name} has stopped working on the {$movement->name} assessment ({$assessment->period_label}): ".trim($reason).' '
                    .($remaining === [] ? 'Nobody is assessing it now. Please assign an assessor.' : 'Still assigned: '.implode(', ', $remaining).'. Assign another assessor if needed.'),
                '/movements/'.$movement->slug, 'action',
            ), except: $assessor);

            return $assessment;
        });
    }
}
