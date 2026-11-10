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

        return $task->awaitsConfirmation()
            ? Response::allow()
            : Response::deny('This task is not waiting for confirmation.');
    }
}
