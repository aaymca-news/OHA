<?php

namespace App\Http\Controllers;

use App\Actions\Oha\DeleteAssessment;
use App\Actions\Oha\HandBackAssessment;
use App\Actions\Oha\OpenAssessment;
use App\Enums\ArtefactKind;
use App\Enums\DocumentPurpose;
use App\Enums\FindingSeverity;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\Movement;
use App\Oha\CategoryRows;
use App\Oha\DqaSummary;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Scorer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AssessmentController extends Controller
{
    /** Every assessment, with who holds it now and when it is due. */
    public function index(Request $request): View
    {
        abort_unless($request->user()->isSecretariat(), 403);

        return view('assessments.index', [
            'assessments' => Assessment::query()
                ->with(['movement', 'score.band', 'workItem', 'artefacts.status', 'opener'])
                ->latest('opened_at')->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request, Movement $movement, OpenAssessment $open): RedirectResponse
    {
        $data = $request->validate([
            'period_label' => ['required', 'string', 'max:40'],
            'period_start' => ['required', 'date_format:Y-m'],
        ]);

        $assessment = $open->handle($request->user(), $movement, $data['period_label'], Carbon::createFromFormat('Y-m', $data['period_start'])->startOfMonth());

        return redirect()->route('assessments.show', $assessment)->with('status', 'Assessment opened. Upload the completed OHA form to begin.');
    }

    /** An assessor stops working on the assessment; the Administrators are asked to reassign it. */
    public function handBack(Request $request, Assessment $assessment, HandBackAssessment $handBack): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Say why you are stopping.']);
        $handBack->handle($assessment, $request->user(), $data['reason']);

        return redirect()->route('movements.show', $assessment->movement)
            ->with('status', 'You no longer work on this assessment. The Administrators have been asked to assign an assessor.');
    }

    /** The Super Administrator deletes the whole assessment. */
    public function destroy(Request $request, Assessment $assessment, DeleteAssessment $delete): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $movement = $assessment->movement;
        $delete->handle($assessment, $request->user(), $data['confirm_name'], $data['reason']);

        return redirect()->route('movements.show', $movement)->with('status', "The {$movement->name} assessment ({$assessment->period_label}) has been deleted.");
    }

    public function show(Request $request, Assessment $assessment, Scorer $scorer): View
    {
        Gate::authorize('view', $assessment);
        $user = $request->user();

        $assessment->load(['movement', 'opener', 'score.band', 'workItem', 'artefacts.status', 'artefacts.submitter', 'artefacts.approver', 'artefacts.signature.signer']);
        /** @var Collection<string, Artefact> $artefacts */
        $artefacts = $assessment->artefacts->keyBy(fn (Artefact $a) => $a->kind->value);
        $form = $artefacts['form'];

        // Tabs this user may open: each document by its own rule; the timeline and audit
        // trail for the Administrators and the movement's assessors.
        $canAudit = Gate::forUser($user)->allows('viewAudit', $assessment);
        $tabs = collect(['form', 'report', 'odp'])
            ->filter(fn (string $kind) => Gate::forUser($user)->allows('view', $artefacts[$kind]))
            ->when($canAudit, fn ($t) => $t->push('timeline', 'audit'))
            ->values();
        abort_if($tabs->isEmpty(), 403, 'Nothing in this assessment is visible to you yet.');
        $tab = $tabs->contains($request->query('tab')) ? $request->query('tab') : $tabs->first();

        $milestones = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->orderBy('sort_order')->get();
        // The assessors and the Administrators work on the latest upload; everyone else sees
        // the approved one, also while a corrected form waits for approval.
        $seesDrafts = $user->oversees() || $user->canAssess($assessment->movement);
        $uploads = $tabs->contains('form') ? $form->uploads()->with('uploader', 'approver')->latest('id')->get()
            ->filter(fn (FormUpload $u) => $seesDrafts || $u->isApproved())->values() : collect();
        $upload = $uploads->first()?->load('findings.category', 'findings.resolver');
        $formMilestone = $milestones->firstWhere('gate_code', 'form');

        return view('assessments.show', [
            'assessment' => $assessment,
            'artefacts' => $artefacts,
            'tabs' => $tabs,
            'tab' => $tab,
            'milestones' => $milestones,
            'rows' => CategoryRows::for($assessment),
            'upload' => $upload,
            'uploads' => $uploads,
            'previewing' => $uploads->firstWhere('id', (int) $request->query('upload')) ?? $upload,
            // Before validation the score is provisional: recomputed from the answers, not yet recorded.
            'provisional' => $upload !== null ? array_sum(array_filter($scorer->score($upload->answers, $upload->form_meta['missing_sheets'] ?? []))) : null,
            'dqa' => $upload !== null ? DqaSummary::of($upload->findings, (bool) ($formMilestone?->overdue || $formMilestone?->completed_late)) : null,
            'openGaps' => $upload?->findings->where('severity', FindingSeverity::Missing)->whereNull('resolved_at')->count() ?? 0,
            'documents' => fn (string $kind) => $artefacts[$kind]->documents()->where('purpose', '!=', DocumentPurpose::Uploaded)->latest('id')->get(),
            // The report check for a version: worked out now, against the current OHA form.
            // Only for those who see the movement's gaps (its assessors and the Administrators).
            'reportCheck' => fn (Document $version) => $version->extracted !== null && Gate::forUser($user)->allows('viewGaps', $assessment)
                ? app(ReportChecker::class)->check(ReadReport::fromArray($version->extracted), $assessment)
                : null,
            // Report and ODP versions, newest first. Staff and the Chairperson see only approved ones.
            'versions' => collect(['report', 'odp'])->mapWithKeys(fn (string $kind) => [$kind => $tabs->contains($kind)
                ? $artefacts[$kind]->versions()->with('creator', 'approver')->get()
                    ->each(fn (Document $d, int $i) => $d->setAttribute('number', $i + 1))
                    ->filter(fn (Document $d) => Gate::forUser($user)->allows('download', $d))
                    ->reverse()->values()
                : collect()]),
            'comments' => fn (string $kind) => $artefacts[$kind]->comments()->with('author')->latest('id')->get(),
            'timeline' => $assessment->timelineItems()->whereNull('gate_id')->orderBy('due_on')->orderBy('id')->get(),
            'audit' => $canAudit ? $assessment->auditEvents()->with('actor')->latest('id')->limit(200)->get() : collect(),
            'canAudit' => $canAudit,
            'kinds' => ArtefactKind::cases(),
        ]);
    }
}
