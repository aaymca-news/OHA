<?php

namespace App\Policies;

use App\Enums\HolderRole;
use App\Models\Assessment;
use App\Models\User;
use App\Oha\FollowUpSchedule;
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
        // Handing back is giving up an assignment: only someone assigned to the movement.
        if (! $user->isAssignedTo($assessment->movement)) {
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
     * Closing the assessment (Stage 1 done), once its ODP is approved: its assessors, an
     * Administrator or the Super Administrator. It freezes the OHA form, the report and the
     * ODP, sets Baseline 1 and starts the follow-up. The Board Chairperson's signature is
     * not needed (it stays optional, before or after).
     */
    public function close(User $user, Assessment $assessment): Response
    {
        if (! $user->canAssess($assessment->movement)) {
            return Response::deny('The assessment is closed by the movement’s assessors or an Administrator.');
        }
        if ($assessment->isClosed()) {
            return Response::deny('This assessment is already closed.');
        }
        // Ready when the work is with whoever closes it: the ODP approved, nothing waiting for approval.
        $holder = $assessment->workItem?->holder_role;

        return match (true) {
            $holder === HolderRole::Closer => Response::allow(),
            $holder === HolderRole::Approver && (bool) $assessment->odp()->first()?->isPublished() => Response::deny('Something is waiting for the Administrators’ approval. Close the assessment once they have decided.'),
            default => Response::deny('The assessment can be closed once its ODP is approved.'),
        };
    }

    /**
     * Stage 2, ODP follow-up (once the assessment is closed): the movement's National
     * General Secretary, its assessors and the Administrators. Not the Board Chairperson.
     */
    public function viewFollowUp(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isClosed()) {
            return Response::deny('Follow-up starts once the assessment is closed.');
        }

        return $user->active && ($this->worksOn($user, $assessment) || $user->isGeneralSecretaryOf($assessment->movement))
            ? Response::allow()
            : Response::deny('The ODP follow-up is seen by the movement’s National General Secretary, its assessors and the Administrators.');
    }

    /** A follow-up meeting is recorded by an assessor on the movement, once the assessment is closed. */
    public function recordFollowUp(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isClosed()) {
            return Response::deny('Follow-up meetings start once the assessment is closed.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.');
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Follow-up meetings are recorded by the movement’s assessors.');
    }

    /**
     * Running the follow-ups: setting each one's date, opening it and closing (locking) it
     * once its meeting is recorded. The movement's assessors, an Administrator or the Super
     * Administrator. Whether a step can be taken now is FollowUpWindows' to say.
     */
    public function runFollowUps(User $user, Assessment $assessment): Response
    {
        if ($denied = $this->notInFollowUp($assessment)) {
            return $denied;
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('The follow-ups are run by the movement’s assessors or an Administrator.');
    }

    /**
     * Re-scoring the answers for the next baseline (a re-uploaded Excel form, or answer by
     * answer on the site): while a follow-up is open, by the movement's assessors, an
     * Administrator or the Super Administrator. Closing the follow-up is the sign-off.
     */
    public function rescore(User $user, Assessment $assessment): Response
    {
        $run = $this->runFollowUps($user, $assessment);
        if ($run->denied()) {
            return $run;
        }
        $next = FollowUpSchedule::next($assessment);

        return $next !== null && $next['state'] === FollowUpSchedule::OPEN
            ? Response::allow()
            : Response::deny('Open the follow-up first: the answers are re-scored while a follow-up is open.');
    }

    /** Reopening a follow-up already closed: an Administrator only. */
    public function manageFollowUps(User $user, Assessment $assessment): Response
    {
        if ($denied = $this->notInFollowUp($assessment)) {
            return $denied;
        }

        return $user->active && $user->isAdmin()
            ? Response::allow()
            : Response::deny('Only an Administrator reopens a follow-up.');
    }

    /** Why the follow-ups cannot be worked on, or null: the assessment is closed and its cycle is not. */
    private function notInFollowUp(Assessment $assessment): ?Response
    {
        return match (true) {
            ! $assessment->isClosed() => Response::deny('The follow-up starts once the assessment is closed.'),
            $assessment->isFollowUpClosed() => Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.'),
            default => null,
        };
    }

    /**
     * Closing the follow-up cycle (Stage 2 done): an assessor on the movement, or an
     * Administrator. Whether it is ready to close is CloseFollowUp's to say.
     */
    public function closeFollowUp(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isClosed()) {
            return Response::deny('The follow-up starts once the assessment is closed.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is already closed.');
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('The cycle is closed by the movement’s assessors or an Administrator.');
    }

    /**
     * Carrying the ODP's unfinished tasks into the movement's next assessment: its
     * assessors, before or after the follow-up cycle is closed (closing may leave tasks open).
     */
    public function carryOdpTasks(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isClosed()) {
            return Response::deny('Tasks are carried over once the assessment is closed.');
        }

        return $user->canAssess($assessment->movement)
            ? Response::allow()
            : Response::deny('Unfinished tasks are carried over by the movement’s assessors.');
    }

    /** The overall review of the ODP, once the assessment is closed: the movement's assessors. */
    public function reviewOdp(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isClosed()) {
            return Response::deny('The ODP is reviewed once the assessment is closed.');
        }
        if ($assessment->isFollowUpClosed()) {
            return Response::deny('This ODP’s follow-up cycle is closed: it is kept as a record.');
        }

        // Its assessors, or an Administrator (who may also close the cycle, which needs it).
        return $user->canAssess($assessment->movement)
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
