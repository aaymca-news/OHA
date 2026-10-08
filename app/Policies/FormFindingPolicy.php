<?php

namespace App\Policies;

use App\Enums\FindingSeverity;
use App\Models\FormFinding;
use App\Models\User;
use App\Oha\FindingFields;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class FormFindingPolicy
{
    /**
     * A gap can be closed off with a note (e.g. the NGS confirms a figure by email), and
     * an item for review marked as reviewed, by the movement's assessors or the
     * Administrators. A note changes no answer and no score; typing the answer does.
     */
    public function resolve(User $user, FormFinding $finding): Response
    {
        if ($finding->severity === FindingSeverity::Error) {
            return Response::deny('This upload was refused. Upload the right form instead.');
        }

        $upload = $finding->formUpload;
        if ($upload->artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form is frozen.');
        }
        if ($upload->artefact->currentUpload()->value('id') !== $upload->id) {
            return Response::deny('This is the approved upload. Open the working version to settle what it found.');
        }

        $movement = $upload->artefact->assessment->movement;

        return $user->oversees() || $user->canAssess($movement)
            ? Response::allow()
            : Response::deny('Only the assigned assessors or the Administrators can resolve missing items.');
    }

    /**
     * Typing the answer the form asks for: for something missing (an answer, a group of
     * percentages, the areas of improvement, a sign-off), or to correct an answer that
     * could not be read or was in the wrong unit.
     */
    public function answer(User $user, FormFinding $finding): Response
    {
        if (FindingFields::for($finding) === null) {
            $kind = $finding->ref !== null ? explode(':', $finding->ref, 2)[0] : null;

            return Response::deny($kind === 'sheet'
                ? 'A whole missing sheet cannot be typed in. Ask the movement for the complete form and upload it.'
                : 'This cannot be answered by typing.');
        }
        if ($finding->resolved_at !== null) {
            return Response::deny('This is resolved with a note. Reopen it to type the answer instead.');
        }

        return Gate::forUser($user)->inspect('supply', $finding->formUpload);
    }
}
