<?php

namespace App\Http\Controllers;

use App\Actions\Oha\AssignAssessors;
use App\Enums\Stage;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\HealthBand;
use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\Zone;
use App\Oha\CategoryRows;
use App\Queries\AllianceInsights;
use App\Queries\MovementSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MovementController extends Controller
{
    /** Every movement, filterable by zone, membership status and band. */
    public function index(Request $request, AllianceInsights $insights): View
    {
        Gate::authorize('viewAny', Movement::class);

        $statuses = MovementStatus::query()
            ->with(['band', 'movement.zone', 'movement.membershipStatus'])
            ->when($request->query('zone'), fn ($q, $zone) => $q->whereHas('movement.zone', fn ($z) => $z->where('code', $zone)))
            ->when($request->query('membership'), fn ($q, $m) => $q->whereHas('movement.membershipStatus', fn ($s) => $s->where('code', $m)))
            ->when($request->query('band'), fn ($q, $band) => $q->where('band_code', $band))
            ->orderByRaw('pct DESC NULLS LAST')->orderBy('name')
            ->get();

        // Per movement: its latest assessment (and what it is waiting on), its assessors,
        // and each category's percentage from the latest approved form.
        $latest = Assessment::query()->with('workItem')->latestPerMovement()->get()->keyBy('movement_id');
        $heat = $insights->heatmap();
        $cells = collect($heat['rows'])->mapWithKeys(fn ($row) => [$row['movement']->id => $row['cells']]);
        $assessors = Movement::query()->with(['assessors' => fn ($q) => $q->where('active', true)->orderBy('name')])->get()
            ->mapWithKeys(fn (Movement $m) => [$m->id => $m->assessors]);

        $stage = fn (MovementStatus $status): Stage => Stage::of($latest[$status->movement_id] ?? null);
        if ($request->query('stage')) {
            $statuses = $statuses->filter(fn (MovementStatus $s) => $stage($s)->value === $request->query('stage'))->values();
        }
        if ($q = trim((string) $request->query('q'))) {
            $statuses = $statuses->filter(fn (MovementStatus $s) => str_contains(mb_strtolower($s->name.' '.$s->movement->city.' '.$s->movement->country), mb_strtolower($q)))->values();
        }

        return view('movements.index', [
            'view' => $request->query('view') === 'table' ? 'table' : 'cards',
            'stage' => $stage,
            'latest' => $latest,
            'cells' => $cells,
            'categories' => $heat['categories'],
            'assessors' => $assessors,
            'pipeline' => $insights->pipeline(),
            'statuses' => $statuses,
            'zones' => Zone::query()->orderBy('sort_order')->get(),
            'memberships' => MembershipStatus::query()->orderBy('sort_order')->get(),
            'bands' => HealthBand::query()->orderBy('sort_order')->get(),
            'filters' => $request->only('zone', 'membership', 'band', 'stage', 'q', 'view'),
        ]);
    }

    public function show(Request $request, Movement $movement): View
    {
        Gate::authorize('view', $movement);
        $user = $request->user();

        $status = MovementStatus::query()->with('band')->findOrFail($movement->id);

        // Every assignment change (and hand-back), newest first: who was assessing, and since when.
        $assignmentHistory = $user->isSecretariat()
            ? AuditEvent::query()->with('actor')->where('action', 'movement.assessors_changed')
                ->where('subject_type', $movement->getMorphClass())->where('subject_id', $movement->id)
                ->latest('id')->get()
            : collect();
        $assignedSince = [];
        foreach ($assignmentHistory->reverse() as $event) {
            foreach ($event->payload['added'] ?? [] as $id) {
                $assignedSince[$id] = $event->occurred_at;
            }
        }
        $snapshot = MovementSnapshot::of($status);

        $assessments = $movement->assessments()->with(['score.band', 'artefacts.status', 'workItem'])->latest('assessed_on')->latest('id')->get()
            ->filter(fn (Assessment $a) => Gate::forUser($user)->allows('view', $a));

        return view('movements.show', [
            'movement' => $movement->load('zone', 'membershipStatus'),
            'status' => $status,
            'latest' => $snapshot->latest,
            'rows' => $snapshot->rows,
            'assessments' => $assessments,
            // Each scored assessment's category scores, oldest first (the last five), to compare.
            'history' => $assessments->filter(fn (Assessment $a) => $a->score !== null)->take(5)->reverse()->values()
                ->map(fn (Assessment $a) => ['assessment' => $a, 'rows' => collect(CategoryRows::for($a))->keyBy('code')]),
            'assessors' => $user->isSecretariat() ? $movement->activeAssessors()->get() : collect(),
            'chair' => $movement->chair()->first(),
            'generalSecretary' => $movement->generalSecretary()->first(),
            'evidenceCount' => $movement->evidence()->count(),
            'profile' => $snapshot->profile,
            'assignmentHistory' => $assignmentHistory,
            'assignedSince' => $assignedSince,
            'underWay' => $movement->assessmentUnderWay(),
            // Stage 1 done (ODP signed): its follow-ups are under way until the next assessment is due.
            'lastDone' => $movement->lastAssessmentDone(),
            'followingUp' => $status->has_odp && $status->next_assessment_on?->isFuture(),
            'canOpen' => Gate::forUser($user)->allows('openAssessment', $movement),
            'canAssign' => Gate::forUser($user)->allows('assignAssessors', $movement),
        ]);
    }

    /** The Administrators choose who assesses a movement, on the Assessments page. */
    public function assignAssessors(Request $request, Movement $movement, AssignAssessors $assign): RedirectResponse
    {
        $data = $request->validate(['assessor_ids' => ['array'], 'assessor_ids.*' => ['integer', 'exists:users,id']]);
        $assign->handle($request->user(), $movement, array_map('intval', $data['assessor_ids'] ?? []));

        // Back to the movement's row on the Assessments page.
        return redirect()->to(url()->previous().'#assessors-'.$movement->slug)->with('status', "Assessors saved for {$movement->name}.");
    }
}
