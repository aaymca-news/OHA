<?php

namespace App\Policies;

use App\Models\OdpTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Stage 2, ODP follow-up, once the assessment is closed (decided 12 Nov 2026): between the
 * follow-ups, the movement's National General Secretary and its assessors add a note or
 * evidence to a task; where every task stands is set together at each follow-up meeting
 * (see RecordFollowUp), by the assessors.
 */
class OdpTaskPolicy
{
    /** A note or evidence on a task: the movement's National General Secretary, and the staff assigned to assess it. */
    public function note(User $user, OdpTask $task): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }
        if (! $task->assessment->isClosed()) {
            return Response::deny('Notes and evidence are added once the assessment is closed.');
        }
        if ($closed = self::closed($task)) {
            return $closed;
        }

        return $user->isGeneralSecretaryOf($task->assessment->movement) || $user->canAssess($task->assessment->movement)
            ? Response::allow()
            : Response::deny('Notes and evidence on the ODP are added by the movement’s National General Secretary and its assessors.');
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
