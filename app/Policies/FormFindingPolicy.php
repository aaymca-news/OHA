<?php

namespace App\Policies;

use App\Enums\FindingSeverity;
use App\Models\FormFinding;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class FormFindingPolicy
{
    /** Warnings that are about one answer, which can be corrected by typing it. */
    private const CORRECTABLE = ['non_numeric', 'wrong_unit', 'invalid_option'];

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

        $upload = $finding->formUpload;
        if ($upload->artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form is frozen.');
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
        $kind = $finding->ref !== null ? explode(':', $finding->ref, 2)[0] : null;
        $answerable = $finding->severity === FindingSeverity::Missing
            ? in_array($kind, ['q', 'g', 'c', 's'], true)
            : $finding->severity === FindingSeverity::Warning && $finding->question_code !== null && in_array($finding->rule, self::CORRECTABLE, true);

        if (! $answerable) {
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
