<?php

namespace App\Http\Controllers;

use App\Actions\Oha\FixReport;
use App\Actions\Oha\ReviewReportFinding;
use App\Models\Artefact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fixing what the report check found: written into the report as a new version (from the
 * OHA form, or as typed), or marked as reviewed with a note.
 */
class ReportFixController extends Controller
{
    public function fix(Request $request, Artefact $artefact, FixReport $fix): RedirectResponse
    {
        $data = $request->validate([
            'ref' => ['required', 'string', 'max:120'],
            'value' => ['nullable', 'string', 'max:10000'],
            'growth' => ['nullable', 'string', 'max:10000'],
        ]);
        $version = $fix->handle($artefact, $request->user(), $data['ref'], $data);

        return $this->back($artefact, 'Written into the report as version '.$version->versionNumber().'. The check has run again on it.');
    }

    public function fromForm(Request $request, Artefact $artefact, FixReport $fix): RedirectResponse
    {
        $version = $fix->fromForm($artefact, $request->user());

        return $this->back($artefact, 'Filled in from the OHA form, as version '.$version->versionNumber().'. The check has run again on it.');
    }

    public function review(Request $request, Artefact $artefact, ReviewReportFinding $review): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'size:32'],
            'message' => ['required', 'string', 'max:1000'],
            'note' => ['required', 'string', 'max:2000'],
        ], ['note.required' => 'Say what you checked.']);
        $review->mark($artefact, $request->user(), $data['key'], $data['message'], $data['note']);

        return $this->back($artefact, 'Marked as reviewed.');
    }

    public function reopen(Request $request, Artefact $artefact, ReviewReportFinding $review): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'size:32']]);
        $review->unmark($artefact, $request->user(), $data['key']);

        return $this->back($artefact, 'Reopened.');
    }

    private function back(Artefact $artefact, string $status): RedirectResponse
    {
        return redirect()->route('assessments.show', ['assessment' => $artefact->assessment_id, 'tab' => 'report'])->withFragment('report-check')
            ->with('status', $status);
    }
}
