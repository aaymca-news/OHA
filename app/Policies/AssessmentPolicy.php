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
     * The assessors on the movement set its timeline; the Administrators may set any.
     */
    public function editTimeline(User $user, Assessment $assessment): Response
    {
        return $user->oversees() || $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Only the assessors on this movement and the Administrators can change its timeline.');
    }
}
