<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Administrators decide which staff assess a movement, and may reassign them
 * at any time. Staff cannot give themselves the work.
 *
 * Every change is audited on the movement and on each assessment still in progress,
 * so it shows in that assessment's audit trail. Those added and those removed are told.
 */
final class AssignAssessors
{
    use EnforcesPolicy;

    /**
     * @param  list<int>  $userIds
     */
    public function handle(User $assigner, Movement $movement, array $userIds): Movement
    {
        $this->ensure($assigner, 'assignAssessors', $movement);

        $people = User::query()->whereKey($userIds)->get();
        if ($people->count() !== count(array_unique($userIds)) || $people->contains(fn (User $u) => ! $u->isAssessor() || ! $u->active)) {
            throw new WorkflowRuleBroken('Only active AAYMCA staff (including the Administrators) can be assigned to assess a movement.');
        }

        return DB::transaction(function () use ($assigner, $movement, $people): Movement {
            $result = $movement->assessors()->sync($people->pluck('id')->all());

            if ($result['attached'] === [] && $result['detached'] === []) {
                return $movement;
            }

            $added = User::query()->whereKey($result['attached'])->orderBy('name')->get();
            $removed = User::query()->whereKey($result['detached'])->orderBy('name')->get();
            $payload = [
                'added' => $result['attached'],
                'removed' => $result['detached'],
                'added_names' => $added->pluck('name')->all(),
                'removed_names' => $removed->pluck('name')->all(),
                'now' => $movement->assessors()->orderBy('name')->pluck('name')->all(),
            ];

            Audit::record($assigner, 'movement.assessors_changed', $movement, payload: $payload);
            foreach (self::inProgress($movement) as $assessment) {
                Audit::record($assigner, 'assessment.assessors_changed', $movement, $assessment, payload: $payload);
            }

            Notify::send($added, new WorkflowNotice(
                "You are assigned to assess {$movement->name}",
                "{$assigner->name} assigned you to assess {$movement->name}. You can now open its assessments and upload its OHA form.",
                '/movements/'.$movement->slug, 'info',
            ), except: $assigner);
            Notify::send($removed, new WorkflowNotice(
                "You no longer assess {$movement->name}",
                "{$assigner->name} reassigned the assessment of {$movement->name}".($added->isNotEmpty() ? ' to '.$added->pluck('name')->join(', ', ' and ') : '').'.',
                '/movements/'.$movement->slug, 'info',
            ), except: $assigner);

            return $movement;
        });
    }

    /**
     * Assessments of the movement that are still under way (someone still holds them).
     *
     * @return Collection<int, Assessment>
     */
    public static function inProgress(Movement $movement)
    {
        return $movement->assessments()->whereHas('workItem')->get();
    }
}
