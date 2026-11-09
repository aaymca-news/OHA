<?php

namespace App\Queries;

use App\Enums\ArtefactKind;
use App\Enums\FindingSeverity;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\AssessmentMilestone;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\User;
use App\Oha\CategoryRows;
use App\Oha\DqaSummary;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Oha\Scorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything the assessment page shows to one person: the tabs they may open, the form
 * upload in view, the report and ODP versions, the check results, the timeline and the
 * audit trail. The controller only authorises and renders.
 */
final readonly class AssessmentPage
{
    public function __construct(private Scorer $scorer, private EvidenceLibrary $evidence) {}

    /**
     * @param  array{tab?: mixed, view?: mixed, who?: int|null, upload?: int|null}  $asked  from the address: ?tab, ?view=working, ?who, ?upload
     * @return array<string, mixed>
     */
    public function for(User $user, Assessment $assessment, array $asked): array
    {
        $assessment->load(['movement', 'opener', 'score.band', 'workItem', 'artefacts.status', 'artefacts.submitter', 'artefacts.approver', 'artefacts.signature.signer']);
        /** @var Collection<string, Artefact> $artefacts */
        $artefacts = $assessment->artefacts->keyBy(fn (Artefact $a) => $a->kind->value);
        $form = $artefacts['form'];
        $gate = Gate::forUser($user);

        // Tabs this user may open: each document by its own rule; the timeline and audit
        // trail for the Administrators and the movement's assessors.
        $canAudit = $gate->allows('viewAudit', $assessment);
        $who = $asked['who'] ?? null;
        $tabs = collect(['form', 'report', 'odp'])
            ->filter(fn (string $kind) => $gate->allows('view', $artefacts[$kind]))
            ->when($canAudit, fn ($t) => $t->push('timeline', 'audit'))
            ->values();
        abort_if($tabs->isEmpty(), 403, 'Nothing in this assessment is visible to you yet.');
        $tab = $tabs->contains($asked['tab'] ?? null) ? $asked['tab'] : $tabs->first();

        $milestones = AssessmentMilestone::query()->where('assessment_id', $assessment->id)->orderBy('sort_order')->get();
        // The assessors and the Administrators work on the latest upload; everyone else sees
        // the approved one, also while a corrected form waits for approval.
        $seesDrafts = $gate->allows('viewDrafts', $assessment);
        $uploads = $tabs->contains('form') ? $form->uploads()->with('uploader', 'approver')->latest('id')->get()
            ->filter(fn (FormUpload $u) => $seesDrafts || $u->isApproved())->values() : collect();
        // The last approved upload is what everyone sees as current; those working on it can
        // open the newer working upload (?view=working) to correct it.
        $approvedUpload = $uploads->first(fn (FormUpload $u) => $u->isApproved());
        $working = $uploads->first();
        $upload = (($asked['view'] ?? null) === 'working' || $approvedUpload === null ? $working : $approvedUpload)
            ?->load('findings.category', 'findings.resolver');
        $formMilestone = $milestones->firstWhere('gate_code', 'form');

        return [
            'assessment' => $assessment,
            'artefacts' => $artefacts,
            'tabs' => $tabs,
            'tab' => $tab,
            'milestones' => $milestones,
            'rows' => CategoryRows::for($assessment),
            'upload' => $upload,
            'uploads' => $uploads,
            'previewing' => $uploads->firstWhere('id', (int) ($asked['upload'] ?? 0)) ?? $upload,
            // A newer upload than the approved one, being worked on: seen only by those working on it.
            'workingUpload' => $approvedUpload !== null && $working !== null && $working->id !== $approvedUpload->id ? $working : null,
            // Before validation the score is provisional: recomputed from the answers, not yet recorded.
            'provisional' => $upload !== null ? array_sum(array_filter($this->scorer->score($upload->answers, $upload->form_meta['missing_sheets'] ?? []))) : null,
            'dqa' => $upload !== null ? DqaSummary::of($upload->findings, (bool) ($formMilestone?->overdue || $formMilestone?->completed_late)) : null,
            'openGaps' => $upload?->findings->where('severity', FindingSeverity::Missing)->whereNull('resolved_at')->count() ?? 0,
            // The evidence library: its documents and their links, worked out once when a tab asks.
            'evidence' => fn () => once(fn () => $this->evidence->for($assessment)),
            // The report check for a version: worked out now, against the current OHA form.
            // Only for those who see the movement's gaps (its assessors and the Administrators).
            'reportCheck' => fn (Document $version) => $version->extracted !== null && $gate->allows('viewGaps', $assessment)
                ? app(ReportChecker::class)->check(ReadReport::fromArray($version->extracted), $assessment)
                : null,
            // Report and ODP versions, newest first. Staff and the Chairperson see only approved ones.
            'versions' => collect(['report', 'odp'])->mapWithKeys(fn (string $kind) => [$kind => $tabs->contains($kind)
                ? $artefacts[$kind]->versions()->with('creator', 'approver')->get()
                    ->each(fn (Document $d) => $d->setAttribute('number', $d->versionNumber()))
                    ->filter(fn (Document $d) => $gate->allows('download', $d))
                    ->reverse()->values()
                : collect()]),
            'comments' => fn (string $kind) => $artefacts[$kind]->comments()->with('author')->latest('id')->get(),
            'timeline' => $assessment->timelineItems()->whereNull('gate_id')->orderBy('due_on')->orderBy('id')->get(),
            // The work, and apart from it the download log: who downloaded the movement's files, and when.
            // The work can be narrowed to one person (?who=), to see what each assessor did.
            // A page at a time (?audit_page=), keeping the tab and the person chosen.
            'audit' => $canAudit ? $assessment->auditEvents()->where('action', '!=', 'file.downloaded')
                ->when($who, fn ($q, int $id) => $q->where('actor_id', $id))->with('actor')->latest('id')
                ->paginate(config('oha.per_page'), pageName: 'audit_page')->withQueryString() : collect(),
            'auditPeople' => $canAudit ? User::query()->whereIn('id', $assessment->auditEvents()->where('action', '!=', 'file.downloaded')->whereNotNull('actor_id')->select('actor_id'))
                ->orderBy('name')->get(['id', 'name']) : collect(),
            'who' => $who,
            'downloads' => $canAudit ? $assessment->auditEvents()->where('action', 'file.downloaded')->with('actor')->latest('id')->limit(200)->get() : collect(),
            'canAudit' => $canAudit,
            // The latest change to the form (with its gaps), the report or the ODP, for those working on it.
            'lastChange' => $seesDrafts ? fn (string $kind) => $assessment->auditEvents()
                ->where(fn ($q) => $q->where('action', 'like', $kind.'.%')->when($kind === 'form', fn ($q) => $q->orWhere('action', 'like', 'gap.%')))
                ->with('actor')->latest('id')->first() : null,
            'kinds' => ArtefactKind::cases(),
        ];
    }
}
