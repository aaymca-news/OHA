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
 *   upload    the staff assigned to the movement, and the Administrators, upload the OHA form and
 *             each version of the report and ODP, until the ODP is signed
 *   linkDrive the same people link the Google Drive document the ODP is written in
 *   syncDrive and ask the platform to read it now (it also does so by itself)
 *   submit    they hand each one in for approval
 *   approve   any Administrator approves what is submitted, their own submission too; on a movement
 *             they assess, they approve their own work straight away. The ODP only once the OHA
 *             form and the report are approved. Approval makes it visible to all AAYMCA staff
 *             (and the report and ODP to the Board Chairperson)
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

    /**
     * Uploading the completed OHA form, or a version of the report or ODP: until the ODP is
     * signed, also while the Administrators decide (they are told of the change), and after
     * approval (it goes back to them).
     */
    public function upload(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind === ArtefactKind::Odp) {
            return $this->odpClosed($user, $artefact) ?? Response::allow();
        }
        // The movement's assessors, and the Administrators, who can change the form and report too.
        if ($denied = $this->notWorkerOf($user, $artefact)) {
            return $denied;
        }
        if ($artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form, the report and the ODP are frozen. Its implementation is followed in Stage 2.');
        }

        return $artefact->kind === ArtefactKind::Report && $artefact->status?->isLocked()
            ? Response::deny('The report unlocks once the OHA form is uploaded and read. It does not need to be approved first.')
            : Response::allow();
    }

    /**
     * Fixing what the report check found, in the report itself (a new version), or marking
     * a finding as reviewed: the movement's assessors and the Administrators, until the ODP
     * is signed.
     */
    public function fix(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind !== ArtefactKind::Report) {
            return Response::deny('Only the report is fixed this way.');
        }
        if (! $user->active || ! ($user->oversees() || $user->canAssess($artefact->assessment->movement))) {
            return Response::deny('Only the assessors on this movement and the Administrators fix the report.');
        }
        if ($artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the OHA form, the report and the ODP are frozen.');
        }

        return $artefact->status?->isLocked()
            ? Response::deny('The report unlocks once the OHA form is uploaded and read.')
            : Response::allow();
    }

    /**
     * Linking (or re-linking) the Google Drive document. Also after signing: Stage 2 is
     * followed in that document, and if the staff move to another copy, the link follows.
     */
    public function linkDrive(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind !== ArtefactKind::Odp) {
            return Response::deny('Only the ODP is linked to Google Drive.');
        }

        return $this->odpTeam($user, $artefact) ?? Response::allow();
    }

    /**
     * Asking the platform to read the linked document in Google Drive now. After signing,
     * a change is noted (not a version).
     */
    public function syncDrive(User $user, Artefact $artefact): Response
    {
        if ($artefact->kind !== ArtefactKind::Odp || $artefact->drive_file_id === null) {
            return Response::deny('The ODP has no Google Drive document linked.');
        }

        return $this->odpTeam($user, $artefact) ?? Response::allow();
    }

    public function submit(User $user, Artefact $artefact): Response
    {
        $denied = $artefact->kind === ArtefactKind::Odp ? $this->odpClosed($user, $artefact) : $this->notWorkerOf($user, $artefact);
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
        if ($artefact->assessment->isFrozen()) {
            return Response::deny('The Board Chairperson has signed the ODP, so the assessment is final.');
        }

        // An Administrator's own work (a movement they assess) needs nobody else: they approve
        // it straight away, with no submission, to make it visible and move it on.
        $ownWork = $user->canAssess($artefact->assessment->movement)
            && in_array($artefact->state, $artefact->kind === ArtefactKind::Form ? [ArtefactState::Ready, ArtefactState::Rejected] : [ArtefactState::Drafted, ArtefactState::Rejected], true);
        if ($artefact->state !== ArtefactState::PendingApproval && ! $ownWork) {
            return Response::deny('This is not awaiting approval.');
        }

        // The ODP rests on the report, which rests on the OHA form: both approved first.
        if ($artefact->kind === ArtefactKind::Odp) {
            $statuses = $artefact->assessment->artefacts()->with('status')->get()->keyBy(fn (Artefact $a) => $a->kind->value);
            $missing = array_values(array_filter([
                ! ($statuses['form']->status->published ?? false) ? 'the OHA form' : null,
                ! ($statuses['report']->status->published ?? false) ? 'the report' : null,
            ]));
            if ($missing !== []) {
                return Response::deny('The ODP can be approved only once '.implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' approved.');
            }
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

    /** Why someone may not work with the ODP's Google Drive document, or null: its assessors and the Administrators, once it is open. */
    private function odpTeam(User $user, Artefact $odp): ?Response
    {
        if (! $user->oversees() && ($denied = $this->notAssessorOf($user, $odp))) {
            return $denied;
        }

        return $odp->status?->isLocked()
            ? Response::deny('The ODP unlocks once a version of the report is saved. The report does not need to be approved first.')
            : null;
    }

    /** Why someone may not work on the form or report, or null: its assessors and the Administrators. */
    private function notWorkerOf(User $user, Artefact $artefact): ?Response
    {
        return $user->oversees() ? null : $this->notAssessorOf($user, $artefact);
    }

    private function notAssessorOf(User $user, Artefact $artefact): ?Response
    {
        $movement = $artefact->assessment->movement;

        return $user->canAssess($movement)
            ? null
            : Response::deny("You are not assigned to assess {$movement->name}. The Administrators assign assessors.");
    }
}
