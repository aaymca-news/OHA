<?php

namespace App\Http\Controllers;

use App\Actions\Oha\Rescore;
use App\Models\Assessment;
use App\Oha\Report\JourneyPdf;
use App\Oha\Report\JourneyWorkbook;
use App\Queries\BaselinePage;
use App\Support\Audit;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * An assessment's baselines (decided 12 Nov 2026): Baseline 1 from the closed assessment,
 * one more as each follow-up is closed; and, while a follow-up is open, re-scoring its
 * answers (a re-uploaded Excel form, or answer by answer on the site).
 */
class BaselineController extends Controller
{
    public function show(Request $request, Assessment $assessment, BaselinePage $page): View
    {
        Gate::authorize('viewFollowUp', $assessment);
        $assessment->load('movement');

        return view('baselines.show', ['assessment' => $assessment] + $page->for($assessment, $request->integer('baseline') ?: null) + [
            'canRescore' => Gate::allows('rescore', $assessment),
        ]);
    }

    /** The cycle's journey as a PDF (growth curve, rings, categories, what changed). */
    public function pdf(Request $request, Assessment $assessment, JourneyPdf $pdf): Response
    {
        Gate::authorize('viewFollowUp', $assessment);
        $content = $pdf->build($assessment);
        Audit::record($request->user(), 'journey.downloaded', $assessment, $assessment, payload: ['format' => 'pdf']);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::fileName($assessment, 'pdf').'"',
        ]);
    }

    /** The cycle's journey as an Excel workbook (baselines, categories, changes, evidence gaps). */
    public function workbook(Request $request, Assessment $assessment, JourneyWorkbook $workbook): Response
    {
        Gate::authorize('viewFollowUp', $assessment);
        $content = $workbook->build($assessment);
        Audit::record($request->user(), 'journey.downloaded', $assessment, $assessment, payload: ['format' => 'xlsx']);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.self::fileName($assessment, 'xlsx').'"',
        ]);
    }

    /** One answer changed on the site (or the income percentages together), with the reason. */
    public function change(Request $request, Assessment $assessment, Rescore $rescore): RedirectResponse
    {
        $data = $request->validate([
            'ref' => ['required', 'string', 'max:40'],
            'value' => ['nullable', 'string', 'max:2000'],
            'shares' => ['array'],
            'shares.*' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:2000'],
        ], ['reason.required' => 'Say why the answer changed, for example: “The board approved the policy in March.”']);
        $isGroup = str_starts_with($data['ref'], 'g:');
        $rescore->change($assessment, $request->user(), $data['ref'], $isGroup ? ($data['shares'] ?? []) : (string) ($data['value'] ?? ''), $data['reason']);

        return redirect()->to(route('baselines.show', $assessment).'#'.($isGroup ? 'Q214' : substr($data['ref'], 2)))
            ->with('status', 'The answer is changed and the score worked out again. It counts once the follow-up is closed.');
    }

    /** An answer changed, set back to the starting baseline's. */
    public function undo(Request $request, Assessment $assessment, Rescore $rescore): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $rescore->undo($assessment, $request->user(), $data['code']);

        return redirect()->to(route('baselines.show', $assessment).'#changes')->with('status', 'The answer is set back to what it was.');
    }

    /** The movement's re-filled Excel form: its answers become the answers re-scored. */
    public function upload(Request $request, Assessment $assessment, Rescore $rescore): RedirectResponse
    {
        $data = $request->validate([
            'form' => UploadLimit::rule(),
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['form.required' => 'Choose the re-filled OHA form (.xlsx).'] + UploadLimit::messages('form'));
        $file = $request->file('form');
        $upload = $rescore->upload($assessment, $request->user(), (string) $file->getRealPath(), $file->getClientOriginalName(), $data['note'] ?? null);
        $count = count($upload->changed);

        return redirect()->to(route('baselines.show', $assessment).'#changes')
            ->with('status', 'The form is read and its answers re-scored: '.($count === 0 ? 'no answer changed.' : $count.' '.($count === 1 ? 'answer' : 'answers').' changed.'));
    }

    /** "Zambia YMCA - OHA cycle journey - Feb 2026.pdf", safe as a file name. */
    private static function fileName(Assessment $assessment, string $extension): string
    {
        $name = $assessment->movement->name.' - OHA cycle journey - '.$assessment->period_label;

        return trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#', '-', $name)).'.'.$extension;
    }
}
