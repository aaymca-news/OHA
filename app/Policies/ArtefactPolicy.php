<?php

namespace App\Policies;

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Models\Artefact;
use App\Models\User;
use App\Support\Approvers;
use Illuminate\Auth\Access\Response;

/**
 * Who may act on a form, report or ODP. Every refusal says why, so a screen can
 * show the reason instead of a button that quietly does nothing.
 *
 *   upload    the staff assigned to the movement upload the OHA form, and each version of the report;
 *             they and the Administrators add versions of the ODP until it is signed
 *   linkDrive the same people link the Google Drive document the ODP is written in
 *   syncDrive and ask the platform to read it now (it also does so by itself)
 *   submit    they hand each one in for approval
 *   approve   any Administrator except the one who submitted it: this lets the staff go on,
 *             and makes it visible to all AAYMCA staff (and the report and ODP to the Board Chairperson)
 *   sign      the Board Chairperson signs the approved ODP, which makes it "Validated"
 */
class ArtefactPolicy
{
    /**
     * Work in progress: the movement's assessors and the Administrators. Once
     * approved: every AAYMCA staff member. The Board Chairperson: the approved OHA
     * form, report and ODP of their own movement (in Resources).
     */
    public function view(User $user, Artefact $artefact): Response
    {
        if (! $user->active) {
            return Response::deny('Your account is not active.');
        }

        $movement = $artefact->assessment->movement;

        if (! $user->isSecretariat()) {
            if (! $user->isChairOf($movement)) {
                return Response::deny('You see only your own movement.');
            }

            return $artefact->isPublished()
                ? Response::allow()
                : Response::deny('This has not been approved yet.');
        }

        if ($artefact->isPublished() || $user->oversees() || $user->canAssess($movement)) {
            return Response::allow();
        }

        return Response::deny("Only the assessors on {$movement->name} and the Administrators see work in progress. It becomes visible to all staff once approved.");
    }

    /** Uploading the completed OHA form, or a version of the report or ODP. */
    public function upload(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind === ArtefactKind::Odp) {
            if ($denied = $this->odpClosed($user, $artefact)) {
                return $denied;
            }

            return $artefact->state === ArtefactState::PendingApproval
                ? Response::deny('This ODP is with the Administrators for approval. You can add a new version once they decide.')
                : Response::allow();
        }
        if ($denied = $this->notAssessorOf($user, $artefact)) {
            return $denied;
        }
        if ($artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form, the report and the ODP are frozen. Its implementation is followed in Stage 2.');
        }

        if ($artefact->kind === ArtefactKind::Report) {
            if ($artefact->status?->isLocked()) {
                return Response::deny('The report unlocks once the OHA form is uploaded and read. It does not need to be approved first.');
            }

            return $artefact->state === ArtefactState::PendingApproval
                ? Response::deny('This report is with the Administrators for approval. You can upload a new version if it is sent back.')
                : Response::allow();
        }

        // An approved form may be corrected until the ODP is signed; it then goes back for approval.
        return $artefact->state === ArtefactState::PendingApproval
            ? Response::deny('This form is with the Administrators. It can be replaced only if it is sent back.')
            : Response::allow();
    }

    /** Linking (or re-linking) the Google Drive document the ODP is written in. */
    public function linkDrive(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind !== ArtefactKind::Odp) {
            return Response::deny('Only the ODP is linked to Google Drive.');
        }

        return $this->odpClosed($user, $artefact) ?? Response::allow();
    }

    /** Asking the platform to read the linked document in Google Drive now. */
    public function syncDrive(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind !== ArtefactKind::Odp || $artefact->drive_file_id === null) {
            return Response::deny('The ODP has no Google Drive document linked.');
        }
        if ($denied = $this->odpClosed($user, $artefact)) {
            return $denied;
        }

        return $artefact->state === ArtefactState::PendingApproval
            ? Response::deny('While the ODP is with the Administrators, changes in Google Drive wait. They are taken once the Administrators decide.')
            : Response::allow();
    }

    public function submit(User $user, Artefact $artefact): Response
    {
        $denied = $artefact->kind === ArtefactKind::Odp ? $this->odpClosed($user, $artefact) : $this->notAssessorOf($user, $artefact);
        if ($denied) {
            return $denied;
        }

        $submittable = $artefact->kind === ArtefactKind::Form
            ? [ArtefactState::Ready, ArtefactState::Rejected]
            : [ArtefactState::Drafted];

        if (! in_array($artefact->state, $submittable, true)) {
            return Response::deny(match (true) {
                $artefact->state === ArtefactState::NotStarted && $artefact->kind === ArtefactKind::Form => 'Upload the completed OHA form first.',
                $artefact->state === ArtefactState::NotStarted && $artefact->kind === ArtefactKind::Report => 'Upload the report first.',
                $artefact->state === ArtefactState::NotStarted => 'Upload the ODP first.',
                $artefact->state === ArtefactState::RulesFailed => 'The last upload was refused. Upload a corrected form first.',
                $artefact->state === ArtefactState::Rejected && $artefact->kind === ArtefactKind::Report => 'It was sent back: upload the revised version first.',
                $artefact->state === ArtefactState::Rejected => 'It was sent back: upload the revised version first, or change it in Google Drive.',
                $artefact->state === ArtefactState::Approved && $artefact->kind !== ArtefactKind::Form => 'This version is already approved. Add a new version to submit changes.',
                default => 'This has already been submitted.',
            });
        }

        return Approvers::availabilityFor($user);
    }

    /**
     * Any Administrator may approve, except the person who submitted it.
     */
    public function approve(User $user, Artefact $artefact): Response
    {
        if (! $user->active || ! $user->isAdmin()) {
            return Response::deny('Only an Administrator approves.');
        }
        if ($artefact->state !== ArtefactState::PendingApproval) {
            return Response::deny('This is not awaiting approval.');
        }
        if ($artefact->submitted_by === $user->id) {
            return Response::deny('You submitted this, and nobody approves their own work. Another Administrator must approve it.');
        }

        return Response::allow();
    }

    /** The Board Chairperson signs the approved ODP, which validates it. */
    public function sign(User $user, Artefact $artefact): Response
    {
        if (! $user->active || ! $user->isChairOf($artefact->assessment->movement)) {
            return Response::deny('Only this movement\'s Board Chairperson signs its ODP.');
        }
        if ($artefact->kind !== ArtefactKind::Odp) {
            return Response::deny('Only the ODP is signed. The OHA form and the report are approved by AAYMCA, not signed.');
        }
        if ($artefact->signature()->exists()) {
            return Response::deny('This has already been signed.');
        }
        if (! $artefact->isApproved()) {
            return Response::deny($artefact->isPublished()
                ? 'A newer version of the ODP is waiting for AAYMCA’s approval. You can sign once it is approved.'
                : 'The ODP has not been approved yet.');
        }

        return Response::allow();
    }

    /**
     * Why someone may not work on the ODP, or null if they may: the movement's
     * assessors and the Administrators work on it, from the moment a report version is
     * saved until the Board Chairperson signs it. The signed ODP is frozen: Stage 2
     * follows its implementation.
     */
    private function odpClosed(User $user, Artefact $odp): ?Response
    {
        if (! $user->oversees() && ($denied = $this->notAssessorOf($user, $odp))) {
            return $denied;
        }
        if ($odp->status?->isLocked()) {
            return Response::deny('The ODP unlocks once a version of the report is saved. The report does not need to be approved first.');
        }
        if ($odp->signature()->exists()) {
            return Response::deny('The Board Chairperson has signed this ODP, so it is frozen. Its implementation is followed in Stage 2.');
        }

        return null;
    }

    private function notAssessorOf(User $user, Artefact $artefact): ?Response
    {
        $movement = $artefact->assessment->movement;

        return $user->canAssess($movement)
            ? null
            : Response::deny("You are not assigned to assess {$movement->name}. The Administrators assign assessors.");
    }
}
