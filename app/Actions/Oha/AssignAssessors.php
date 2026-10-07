<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Support\AssessorChanges;
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

            AssessorChanges::record($assigner, $movement,
                User::query()->whereKey($result['attached'])->orderBy('name')->get(),
                User::query()->whereKey($result['detached'])->orderBy('name')->get());

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
