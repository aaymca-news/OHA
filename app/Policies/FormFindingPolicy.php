<?php

namespace App\Policies;

use App\Enums\FindingSeverity;
use App\Models\FormFinding;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FormFindingPolicy
{
    /**
     * A gap can be closed off the file with a note (e.g. the NGS confirms a figure
     * by email) by the movement's assessors or the Administrators. The file itself is
     * never changed.
     */
    public function resolve(User $user, FormFinding $finding): Response
    {
        if ($finding->severity !== FindingSeverity::Missing) {
            return Response::deny('Only missing information can be resolved; warnings are for review.');
        }

        $movement = $finding->formUpload->artefact->assessment->movement;

        return $user->oversees() || $user->canAssess($movement)
            ? Response::allow()
            : Response::deny('Only the assigned assessors or the Administrators can resolve missing items.');
    }
}
