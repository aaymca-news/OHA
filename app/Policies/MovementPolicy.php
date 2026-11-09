<?php

namespace App\Policies;

use App\Models\Movement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MovementPolicy
{
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
