<?php

namespace App\Http\Controllers;

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\AttachDocument;
use App\Actions\Oha\LinkOdpToDrive;
use App\Actions\Oha\SendBack;
use App\Actions\Oha\SignAsBoard;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\TakeOdpFromDrive;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Enums\ArtefactKind;
use App\Enums\DocumentPurpose;
use App\Enums\FindingSeverity;
use App\Models\Artefact;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Every workflow step on a form, report or ODP. Each hands straight to its action,
 * which checks the policy and gives the reason if it refuses.
 */
class ArtefactController extends Controller
{
    public function upload(Request $request, Artefact $artefact, UploadForm $upload): RedirectResponse
    {
        $request->validate(['form' => ['required', 'file', 'max:'.(int) config('oha.max_upload_kb')]]);
        $file = $request->file('form');

        $stored = $upload->handle($artefact, (string) $file->getRealPath(), $file->getClientOriginalName(), $request->user());

        $refused = $stored->findings->where('severity', FindingSeverity::Error)->count();
        $gaps = $stored->findings->where('severity', FindingSeverity::Missing)->count();

        return $this->back($artefact, $refused > 0
            ? 'The form was refused. See why below, then upload a corrected form.'
            : 'Form read.'.($gaps > 0 ? " {$gaps} item(s) are missing; you can still submit, with an acknowledgement." : ' Nothing is missing.'));
    }

    /** Saves a new version of the report or ODP (.docx or .pdf). */
    public function uploadVersion(Request $request, Artefact $artefact, UploadReportVersion $upload, ReportChecker $checker): RedirectResponse
    {
        if ($artefact->kind === ArtefactKind::Odp) {
            return $this->uploadOdpVersion($request, $artefact, app(UploadOdpVersion::class));
        }

        $request->validate([
            'report' => ['required', 'file', 'max:'.(int) config('oha.max_upload_kb')],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['report.required' => 'Choose the report file to save.']);
        $file = $request->file('report');

        $version = $upload->handle($artefact, (string) $file->getRealPath(), $file->getClientOriginalName(), $request->user(), $request->input('note'));

        $flagged = count($checker->check(ReadReport::fromArray($version->extracted ?? []), $artefact->assessment)['findings']);

        return $this->back($artefact, 'Saved as version '.$version->versionNumber().'. '
            .($flagged === 0 ? 'The report check found nothing missing.' : 'The report check flagged '.$flagged.' '.($flagged === 1 ? 'item' : 'items').' to look at below; '.($flagged === 1 ? 'it does' : 'they do').' not stop you going on.')
            .' Preview it, then submit it for approval when it is ready.');
    }

    private function uploadOdpVersion(Request $request, Artefact $odp, UploadOdpVersion $upload): RedirectResponse
    {
        $request->validate([
            'odp' => ['required', 'file', 'max:'.(int) config('oha.max_upload_kb')],
            'drive_url' => [$odp->drive_file_id === null ? 'required' : 'nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'odp.required' => 'Choose the ODP file to save.',
            'drive_url.required' => 'Add the link to the ODP in Google Drive, so everyone works on the same document.',
        ]);
        $file = $request->file('odp');

        $version = $upload->handle($odp, (string) $file->getRealPath(), $file->getClientOriginalName(), $request->user(),
            $request->input('note'), $request->input('drive_url'));

        return $this->back($odp, 'Saved as version '.$version->versionNumber().'. Preview it, then submit it for approval when it is ready.');
    }

    /** Links (or re-links) the Google Drive document the ODP is written in. */
    public function linkDrive(Request $request, Artefact $artefact, LinkOdpToDrive $link): RedirectResponse
    {
        $data = $request->validate(['drive_url' => ['required', 'string', 'max:2000']], ['drive_url.required' => 'Paste the link to the ODP in Google Drive.']);
        $odp = $link->handle($artefact, $data['drive_url'], $request->user());

        return $this->back($odp, $odp->drive_problem ?? 'Linked to the ODP in Google Drive.');
    }

    /** Reads the linked Google Drive document now, instead of waiting for the next automatic check. */
    public function syncDrive(Request $request, Artefact $artefact, TakeOdpFromDrive $take): RedirectResponse
    {
        $result = $take->handle($artefact, $request->user());

        return $result['outcome'] === TakeOdpFromDrive::FAILED
            ? redirect()->route('assessments.show', ['assessment' => $artefact->assessment_id, 'tab' => 'odp'])->withErrors(['action' => $result['message']])
            : $this->back($artefact, $result['message']);
    }

    public function submit(Request $request, Artefact $artefact, SubmitForApproval $submit): RedirectResponse
    {
        $data = $request->validate(['acknowledge_gaps' => ['boolean'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $submit->handle($artefact, $request->user(), (bool) ($data['acknowledge_gaps'] ?? false), $data['reason'] ?? null);

        return $this->back($artefact, 'Submitted for approval. The Administrators have been told.');
    }

    public function approve(Request $request, Artefact $artefact, ApproveArtefact $approve): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'revision' => ['nullable', 'string', 'max:100'], 'acknowledge_gaps' => ['boolean']]);
        $approve->handle($artefact, $request->user(), $data['note'] ?? null, $data['revision'] ?? null, (bool) ($data['acknowledge_gaps'] ?? false));

        return $this->back($artefact, match ($artefact->kind) {
            ArtefactKind::Form => 'Approved. The assessor can go on to the report, and all AAYMCA staff can now see the form.',
            ArtefactKind::Report => 'Approved. All AAYMCA staff and the Board Chairperson can now see this version of the report.',
            ArtefactKind::Odp => 'Approved. All AAYMCA staff can now see the ODP, and the Board Chairperson is asked to sign it.',
        });
    }

    public function sendBack(Request $request, Artefact $artefact, SendBack $sendBack): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $sendBack->handle($artefact, $request->user(), $data['reason']);

        return $this->back($artefact, 'Sent back to the assessor.');
    }

    public function sign(Request $request, Artefact $artefact, SignAsBoard $sign): RedirectResponse
    {
        $data = $request->validate([
            'signature' => ['required', 'string', 'max:100'],
            'confirm' => ['accepted'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['signature.required' => 'Type your initials or your full name as your signature.', 'confirm.accepted' => 'Tick the box to confirm you validate this document.']);

        $sign->handle($artefact, $request->user(), $data['signature'], true, $data['comment'] ?? null,
            $request->ip(), (string) $request->userAgent());

        return $this->back($artefact, 'Signed. The ODP is now validated by the board.');
    }

    /** A reference file attached to the ODP. */
    public function attach(Request $request, Artefact $artefact, AttachDocument $attach): RedirectResponse
    {
        abort_unless($request->user()->canAssess($artefact->assessment->movement), 403);
        $request->validate(['document' => ['required', 'file', 'mimes:docx,pdf,xlsx', 'max:20480']]);
        $file = $request->file('document');

        $attach->handle($artefact, (string) $file->getRealPath(), $file->getClientOriginalName(), DocumentPurpose::Reference, $request->user());

        return $this->back($artefact, 'Reference document attached.');
    }

    private function back(Artefact $artefact, string $status): RedirectResponse
    {
        return redirect()->route('assessments.show', ['assessment' => $artefact->assessment_id, 'tab' => $artefact->kind->value])
            ->with('status', $status);
    }
}
