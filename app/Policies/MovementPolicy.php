<?php

namespace App\Policies;

use App\Models\Movement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MovementPolicy
{
    /** The list of every National Movement: AAYMCA staff. */
    public function viewAny(User $user): Response
    {
        return $user->active && $user->isSecretariat()
            ? Response::allow()
            : Response::deny('Only AAYMCA staff see the list of National Movements.');
    }

    /** Secretariat users see every movement; a movement's own users see only theirs. */
    public function view(User $user, Movement $movement): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }

        return $user->isSecretariat() || $user->isOfMovement($movement)
            ? Response::allow()
            : Response::deny('You see only your own movement.');
    }

    /** The Administrators add a National Movement when one joins the Alliance. */
    public function create(User $user): Response
    {
        return $user->active && $user->isAdmin()
            ? Response::allow()
            : Response::deny('Only the Administrators add a National Movement.');
    }

    /** …and correct its details: name, country, city, zone, membership. */
    public function update(User $user, Movement $movement): Response
    {
        return $user->active && $user->isAdmin()
            ? Response::allow()
            : Response::deny('Only the Administrators change a National Movement’s details.');
    }

    /**
     * Starting an assessment: the movement's assessors, an Administrator or the Super
     * Administrator. One at a time, and (decided 12 Nov 2026) the next one only once the
     * previous cycle is closed: no band-based date, whenever they are ready.
     */
    public function openAssessment(User $user, Movement $movement): Response
    {
        if (! $user->canAssess($movement)) {
            return Response::deny("You are not assigned to assess {$movement->name}. The Administrators assign assessors.");
        }

        $underWay = $movement->assessmentUnderWay();
        if ($underWay !== null) {
            return Response::deny("{$movement->name}’s {$underWay->period_label} assessment has already started. A new one can start once it is finished (closed).");
        }
        $last = $movement->lastAssessmentDone();

        return $last === null || $last->isFollowUpClosed()
            ? Response::allow()
            : Response::deny("{$movement->name}’s {$last->period_label} cycle is still in follow-up. The next assessment can start once that cycle is closed.");
    }

    /** An approved pause of a movement's follow-up (elections, a crisis) is agreed by an Administrator. */
    public function pauseFollowUp(User $user, Movement $movement): Response
    {
        return $user->active && $user->isAdmin()
            ? Response::allow()
            : Response::deny('A pause of the follow-up is agreed by an Administrator.');
    }

    /** Staff cannot give themselves work: the Administrators assign it. */
    public function assignAssessors(User $user, Movement $movement): Response
    {
        return $user->canAssignAssessors()
            ? Response::allow()
            : Response::deny('Only the Administrators assign staff to assess a movement.');
    }
}
