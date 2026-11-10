<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AssessmentPolicy
{
    /**
     * Secretariat users can open any assessment (each document on it has its own
     * rule). The Board Chairperson sees their own movement's once its OHA form, report or
     * ODP is approved.
     */
    /** The Assessments page, where the work is handed out: the Administrators. */
    public function viewAny(User $user): Response
    {
        return $user->oversees()
            ? Response::allow()
            : Response::deny('Only the Administrators hand out the assessments.');
    }

    public function view(User $user, Assessment $assessment): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }
        if ($user->isSecretariat()) {
            return Response::allow();
        }
        if (! $user->isOfMovement($assessment->movement)) {
            return Response::deny('You see only your own movement.');
        }

        return $assessment->artefacts()->whereHas('status', fn ($q) => $q->where('published', true))->exists()
            ? Response::allow()
            : Response::deny('Nothing from this assessment has been approved yet.');
    }

    /**
     * The audit trail and timeline of the work: the Administrators and the staff
     * assessing that movement.
     */
    public function viewAudit(User $user, Assessment $assessment): Response
    {
        return $user->oversees() || $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('The audit trail is for the Administrators and the staff assessing this movement.');
    }

    /**
     * What the form check found (missing information, warnings) and its data-quality
     * summary: only the staff assessing the movement and the Administrators. Other
     * staff see the approved score, not the movement's gaps.
     */
    public function viewGaps(User $user, Assessment $assessment): Response
    {
        return $this->worksOn($user, $assessment)
            ? Response::allow()
            : Response::deny('Missing information and data quality are shown only to the staff assessing this movement and the Administrators.');
    }

    /**
     * The working versions (a form, report or ODP not yet approved): those working on the
     * assessment, its assessors and the Administrators. Everyone else sees the approved
     * version, also while a corrected one waits for approval.
     */
    public function viewDrafts(User $user, Assessment $assessment): Response
    {
        return $this->worksOn($user, $assessment)
            ? Response::allow()
            : Response::deny('Only the staff assessing this movement and the Administrators see versions not yet approved.');
    }

    /** The Administrators, and the staff assigned to assess the movement. */
    private function worksOn(User $user, Assessment $assessment): bool
    {
        return $user->oversees() || $user->canAssess($assessment->movement);
    }

    /**
     * An assessor on the movement may stop working on an assessment still in progress,
     * handing it back for the Administrators to reassign.
     */
    public function handBack(User $user, Assessment $assessment): Response
    {
        if (! $user->canAssess($assessment->movement)) {
            return Response::deny('Only an assessor on this movement can stop working on its assessment.');
        }

        return $assessment->isUnderWay()
            ? Response::allow()
            : Response::deny('This assessment is complete; there is no work left to hand back.');
    }

    /**
     * Only the Super Administrator deletes an assessment, so the movement shows as
     * not yet assessed. An Administrator cannot.
     */
    public function delete(User $user, Assessment $assessment): Response
    {
        return $user->active && $user->isSuperAdmin()
            ? Response::allow()
            : Response::deny('Only the Super Administrator can delete an assessment.');
    }

    /**
     * Stage 2, ODP follow-up (once the ODP is signed): the movement's own users, its
     * assessors and the Administrators.
     */
    public function viewFollowUp(User $user, Assessment $assessment): Response
    {
        if ($assessment->isUnderWay()) {
            return Response::deny('Follow-up starts once the Board Chairperson has signed the ODP.');
        }

        return $user->active && ($this->worksOn($user, $assessment) || $user->isOfMovement($assessment->movement))
            ? Response::allow()
            : Response::deny('The ODP follow-up is seen by the movement, its assessors and the Administrators.');
    }

    /** A follow-up meeting on the signed ODP is recorded by an assessor on the movement. */
    public function recordFollowUp(User $user, Assessment $assessment): Response
    {
        if ($assessment->isUnderWay()) {
            return Response::deny('Follow-up meetings start once the Board Chairperson has signed the ODP.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.');
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Follow-up meetings are recorded by the movement’s assessors.');
    }

    /**
     * Closing the follow-up cycle (Stage 2 done): an assessor on the movement, or an
     * Administrator. Whether it is ready to close is CloseFollowUp's to say.
     */
    public function closeFollowUp(User $user, Assessment $assessment): Response
    {
        if ($assessment->isUnderWay()) {
            return Response::deny('The follow-up starts once the Board Chairperson has signed the ODP.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is already closed.');
        }

        return $user->active && ($user->canAssess($assessment->movement) || $user->isAdmin())
            ? Response::allow()
            : Response::deny('The cycle is closed by the movement’s assessors or an Administrator.');
    }

    /**
     * Carrying a signed ODP's unfinished tasks into the movement's next assessment: its
     * assessors, before or after the follow-up cycle is closed (closing may leave tasks open).
     */
    public function carryOdpTasks(User $user, Assessment $assessment): Response
    {
        if ($assessment->isUnderWay()) {
            return Response::deny('Tasks are carried over once the Board Chairperson has signed the ODP.');
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Unfinished tasks are carried over by the movement’s assessors.');
    }

    /** The overall review of a signed ODP: the movement's assessors. */
    public function reviewOdp(User $user, Assessment $assessment): Response
    {
        if ($assessment->isUnderWay()) {
            return Response::deny('The ODP is reviewed once the Board Chairperson has signed it.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.');
        }

        // Its assessors, or an Administrator (who may also close the cycle, which needs it).
        return $user->canAssess($assessment->movement) || ($user->active && $user->isAdmin())
            ? Response::allow()
            : Response::deny('The ODP review is written by the movement’s assessors or an Administrator.');
    }

    /**
     * The assessors on the movement set its timeline; the Administrators may set any.
     */
    public function editTimeline(User $user, Assessment $assessment): Response
    {
        return $user->oversees() || $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Only the assessors on this movement and the Administrators can change its timeline.');
    }
}
