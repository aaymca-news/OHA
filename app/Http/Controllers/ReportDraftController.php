<?php

namespace App\Http\Controllers;

use App\Actions\Oha\EditReportDraft;
use App\Actions\Oha\MakeReportFromDraft;
use App\Http\Controllers\Concerns\BacksToAssessment;
use App\Models\Artefact;
use App\Models\ReportDraft;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Support\ReplaceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The report drafted from the OHA form, corrected on its own page, section by section,
 * by whoever may upload the report (the movement's assessors and the Administrators).
 * Each step hands to EditReportDraft, which checks the rules and says why if it refuses.
 */
class ReportDraftController extends Controller
{
    use BacksToAssessment;

    public function show(Request $request, Artefact $artefact, EditReportDraft $edit): View|RedirectResponse
    {
        Gate::authorize('upload', $artefact);
        $draft = $artefact->draft()->with('creator', 'updater', 'formUpload.uploader')->first();
        if ($draft === null) {
            return $this->toReportTab($artefact, 'No draft has been started yet.');
        }
        $upload = $draft->formUpload;

        return view('reports.draft', [
            'artefact' => $artefact,
            'assessment' => $artefact->assessment,
            'draft' => $draft,
            'formChanged' => $edit->formChanged($draft),
            // The form's own wording and answers, shown beside each section it was written from.
            'labels' => $upload->form_meta['labels'] ?? [],
            'answers' => $upload->answers ?? [],
        ]);
    }

    public function start(Request $request, Artefact $artefact, EditReportDraft $edit): RedirectResponse
    {
        $again = $request->boolean('again');
        $edit->start($artefact, $request->user(), $again);

        return redirect()->route('report-draft.show', $artefact)
            ->with('status', $again ? 'The draft was written again from the OHA form.' : 'The report was drafted from the OHA form. Read each section and correct it; anything marked “To complete” needs your words.');
    }

    public function save(Request $request, Artefact $artefact, string $key, EditReportDraft $edit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'text' => ['nullable', 'string', 'max:20000'],
            'items' => ['nullable', 'string', 'max:20000'],
            'lines' => ['nullable', 'array', 'max:3'],
            'lines.*' => ['nullable', 'string', 'max:300'],
            'seen' => ['nullable', 'string', 'max:40'],
        ]);
        $edit->save($this->draft($artefact), $key, $data, $data['seen'] ?? null, $request->user());

        return $this->back($artefact, $key, 'Saved.');
    }

    public function rewrite(Request $request, Artefact $artefact, string $key, EditReportDraft $edit): RedirectResponse
    {
        $edit->rewrite($this->draft($artefact), $key, $request->user());

        return $this->back($artefact, $key, 'Written again from the OHA form.');
    }

    public function add(Request $request, Artefact $artefact, EditReportDraft $edit): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'after' => ['nullable', 'string', 'max:60']], ['title.required' => 'Give the new section a title.']);
        $draft = $edit->add($this->draft($artefact), $data['title'], $data['after'] ?? null, $request->user());
        $added = collect($draft->sections)->last(fn ($s) => $s['kind'] === 'custom' && $s['title'] === trim($data['title']));

        return $this->back($artefact, $added['key'] ?? null, 'Section added. Write it below.');
    }

    public function remove(Request $request, Artefact $artefact, string $key, EditReportDraft $edit): RedirectResponse
    {
        $edit->remove($this->draft($artefact), $key, $request->user());

        return $this->back($artefact, null, 'Section removed.');
    }

    public function facts(Request $request, Artefact $artefact, EditReportDraft $edit): RedirectResponse
    {
        $edit->updateFacts($this->draft($artefact), $request->user());

        return $this->back($artefact, 'scores', 'The scores and figures now match the current OHA form; your corrections elsewhere are kept.');
    }

    /**
     * Makes the draft into the Word report and saves it as the next version. Replacing a
     * version is confirmed as for an upload (ReplaceGuard).
     */
    public function make(Request $request, Artefact $artefact, MakeReportFromDraft $make, ReportChecker $checker): RedirectResponse
    {
        $data = $request->validate([
            'read_every_section' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'seen' => ['nullable', 'integer'],
            'replace' => ['boolean'],
            'replace_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $replacing = Gate::forUser($request->user())->allows('upload', $artefact)
            ? ReplaceGuard::forUpload($artefact, $request->user(), $data['seen'] ?? null, (bool) ($data['replace'] ?? false), $data['replace_reason'] ?? null)
            : [];

        $version = $make->handle($this->draft($artefact), $request->user(), (bool) ($data['read_every_section'] ?? false), $data['note'] ?? null, $replacing);
        $flagged = count($checker->check(ReadReport::fromArray($version->extracted ?? []), $artefact->assessment)['findings']);

        return $this->toReportTab($artefact, 'The Word report was created and saved as version '.$version->versionNumber().'. '
            .($flagged === 0 ? 'The report check found nothing missing.' : 'The report check flagged '.$flagged.' '.($flagged === 1 ? 'item' : 'items').' to look at below.')
            .' Preview it, then submit it for approval when it is ready.');
    }

    public function destroy(Request $request, Artefact $artefact, EditReportDraft $edit): RedirectResponse
    {
        $edit->discard($this->draft($artefact), $request->user());

        return $this->toReportTab($artefact, 'The draft was discarded.');
    }

    private function draft(Artefact $artefact): ReportDraft
    {
        return $artefact->draft()->firstOrFail();
    }

    private function back(Artefact $artefact, ?string $key, string $status): RedirectResponse
    {
        return redirect()->to(route('report-draft.show', $artefact).($key !== null ? '#'.str_replace(':', '-', $key) : ''))->with('status', $status);
    }

    private function toReportTab(Artefact $artefact, string $status): RedirectResponse
    {
        return $this->toTab($artefact, $status, 'report');
    }
}
