<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\Movement;
use App\Models\User;
use App\Oha\FollowUpSchedule;
use Illuminate\Auth\Access\Response;

/**
 * A movement's evidence library:
 *
 *   viewLibrary  AAYMCA staff see every movement's; a movement's own users (Board
 *                Chairperson, National General Secretary) see their own
 *   upload       AAYMCA staff, and the movement's National General Secretary
 *   download     whoever sees the library, and every movement once it is shared
 *   remove       AAYMCA staff anything; the National General Secretary what they added,
 *                until it is counted in something closed (the assessment, a follow-up)
 *   move         to another folder: the same people as remove
 *   share        the Administrators, and the movement's National General Secretary
 */
class EvidencePolicy
{
    public function viewLibrary(User $user, Movement $movement): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }

        return $user->isSecretariat() || $user->isOfMovement($movement)
            ? Response::allow()
            : Response::deny('You see only your own movement’s evidence.');
    }

    /**
     * AAYMCA staff at any time. The movement's National General Secretary once AAYMCA has
     * approved the assessment's ODP, and from then on, after it is signed too.
     */
    public function upload(User $user, Assessment $assessment): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }
        if ($user->isSecretariat()) {
            return Response::allow();
        }
        if (! $user->isGeneralSecretaryOf($assessment->movement)) {
            return Response::deny('Evidence is added by the movement’s National General Secretary and by AAYMCA staff.');
        }

        return $assessment->odp()->first()?->isPublished()
            ? Response::allow()
            : Response::deny('You can add documents once AAYMCA has approved this assessment’s ODP.');
    }

    public function download(User $user, Evidence $evidence): Response
    {
        if (($library = $this->viewLibrary($user, $evidence->assessment->movement))->allowed()) {
            return $library;
        }

        return $user->active && $evidence->isShared()
            ? Response::allow()
            : Response::deny('This document has not been shared with other movements.');
    }

    public function remove(User $user, Evidence $evidence): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }
        if ($user->isSecretariat()) {
            return Response::allow();
        }

        if (! $user->isGeneralSecretaryOf($evidence->assessment->movement) || $evidence->uploaded_by !== $user->id) {
            return Response::deny('You can remove only the documents you added. AAYMCA staff can remove any.');
        }

        // Counted in something now locked (the closed assessment, a closed follow-up): kept as counted.
        return $evidence->isCounted()
            ? Response::deny('This document was counted in '.FollowUpSchedule::label($evidence->counted_in).', which is closed, so it stays. AAYMCA staff can remove it if it must go.')
            : Response::allow();
    }

    /** Correcting the folder is managing the evidence, as removing it is. */
    public function move(User $user, Evidence $evidence): Response
    {
        $remove = $this->remove($user, $evidence);

        return $remove->allowed() || ! $user->active
            ? $remove
            : Response::deny('You can move only the evidence you added. AAYMCA staff can move any.');
    }

    public function share(User $user, Evidence $evidence): Response
    {
        return $this->shareFor($user, $evidence->assessment->movement);
    }

    /** Sharing a document with other movements as it is added (the "share" box on the add form). */
    public function shareFor(User $user, Movement $movement): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }

        return $user->isAdmin() || $user->isGeneralSecretaryOf($movement)
            ? Response::allow()
            : Response::deny('Sharing with other movements is decided by the movement’s National General Secretary or an Administrator.');
    }
}
