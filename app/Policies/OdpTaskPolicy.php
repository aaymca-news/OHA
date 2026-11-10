<?php

namespace App\Policies;

use App\Models\OdpTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Stage 2, ODP follow-up: who reports a task's progress, and who confirms it done.
 * Follow-up starts once the Board Chairperson has signed the ODP.
 */
class OdpTaskPolicy
{
    /** The movement's National General Secretary, and the staff assigned to assess it. */
    public function report(User $user, OdpTask $task): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }
        if ($task->assessment->isUnderWay()) {
            return Response::deny('Progress is reported once the Board Chairperson has signed the ODP.');
        }
        if ($closed = self::closed($task)) {
            return $closed;
        }

        return $user->isGeneralSecretaryOf($task->assessment->movement) || $user->canAssess($task->assessment->movement)
            ? Response::allow()
            : Response::deny('Progress on the ODP is reported by the movement’s National General Secretary and its assessors.');
    }

    /** An assessor on the movement confirms a task reported done, or sends it back. */
    public function confirm(User $user, OdpTask $task): Response
    {
        if (! $user->canAssess($task->assessment->movement)) {
            return Response::deny('A task reported done is confirmed by the movement’s assessors.');
        }
        if ($closed = self::closed($task)) {
            return $closed;
        }

        return $task->awaitsConfirmation()
            ? Response::allow()
            : Response::deny('This task is not waiting for confirmation.');
    }

    /**
     * An assessor drops an unfinished task (instead of carrying it over), or restores it,
     * before the cycle closes.
     */
    public function drop(User $user, OdpTask $task): Response
    {
        if (! $user->canAssess($task->assessment->movement)) {
            return Response::deny('Tasks are dropped by the movement’s assessors.');
        }
        if ($closed = self::closed($task)) {
            return $closed;
        }

        return $task->done || $task->carried_to_assessment_id !== null
            ? Response::deny('Only an unfinished task that has not been carried over can be dropped.')
            : Response::allow();
    }

    /** Refused once the follow-up cycle is closed: it is kept as it was. */
    private static function closed(OdpTask $task): ?Response
    {
        return $task->assessment->isFollowUpClosed()
            ? Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.')
            : null;
    }
}
