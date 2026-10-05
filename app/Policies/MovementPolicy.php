<?php

namespace App\Policies;

use App\Models\Movement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MovementPolicy
{
    /** Secretariat users see all 23 movements; the Board Chairperson sees only their own. */
    public function view(User $user, Movement $movement): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }

        return $user->isSecretariat() || $user->isChairOf($movement)
            ? Response::allow()
            : Response::deny('You see only your own movement.');
    }

    public function openAssessment(User $user, Movement $movement): Response
    {
        return $user->canAssess($movement)
            ? Response::allow()
            : Response::deny("You are not assigned to assess {$movement->name}. The Administrators assign assessors.");
    }

    /** Staff cannot give themselves work: the Administrators assign it. */
    public function assignAssessors(User $user, Movement $movement): Response
    {
        return $user->canAssignAssessors()
            ? Response::allow()
            : Response::deny('Only the Administrators assign staff to assess a movement.');
    }
}
