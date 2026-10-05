<?php

namespace App\Http\Controllers;

use App\Actions\Oha\AssignAssessors;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\FormUpload;
use App\Models\HealthBand;
use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use App\Models\Zone;
use App\Oha\CategoryRows;
use App\Oha\Profile;
use App\Queries\AllianceInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MovementController extends Controller
{
    /** All 23 movements, filterable by zone, membership status and band. */
    public function index(Request $request, AllianceInsights $insights): View
    {
        abort_unless($request->user()->isSecretariat(), 403);

        $statuses = MovementStatus::query()
            ->with(['band', 'movement.zone', 'movement.membershipStatus'])
            ->when($request->query('zone'), fn ($q, $zone) => $q->whereHas('movement.zone', fn ($z) => $z->where('code', $zone)))
            ->when($request->query('membership'), fn ($q, $m) => $q->whereHas('movement.membershipStatus', fn ($s) => $s->where('code', $m)))
            ->when($request->query('band'), fn ($q, $band) => $q->where('band_code', $band))
            ->orderByRaw('pct DESC NULLS LAST')->orderBy('name')
            ->get();

        // Per movement: its latest assessment (and what it is waiting on), its assessors,
        // and each category's percentage from the latest approved form.
        $latest = Assessment::query()->with('workItem')
            ->whereIn('id', Assessment::query()->selectRaw('max(id)')->groupBy('movement_id'))
            ->get()->keyBy('movement_id');
        $heat = $insights->heatmap();
        $cells = collect($heat['rows'])->mapWithKeys(fn ($row) => [$row['movement']->id => $row['cells']]);
        $assessors = Movement::query()->with(['assessors' => fn ($q) => $q->where('active', true)->orderBy('name')])->get()
            ->mapWithKeys(fn (Movement $m) => [$m->id => $m->assessors]);

        $stage = function (MovementStatus $status) use ($latest): string {
            $assessment = $latest[$status->movement_id] ?? null;
            if ($assessment === null) {
                return 'not_assessed';
            }
            $work = $assessment->workItem;

            return $work === null ? 'complete' : ($work->holder_role->value === 'board' ? 'sign' : $work->artefact_kind->value);
        };
        if ($request->query('stage')) {
            $statuses = $statuses->filter(fn (MovementStatus $s) => $stage($s) === $request->query('stage'))->values();
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
        $latest = $status->latest_assessment_id !== null ? Assessment::query()->with('score.band')->find($status->latest_assessment_id) : null;

        // What the latest approved form says about the movement, beyond its score.
        $frozen = $latest !== null ? FormUpload::query()->whereKey($latest->categoryScores()->value('form_upload_id'))->first() : null;
        $profile = $frozen !== null ? (new Profile($frozen->answers ?? [], $frozen->form_meta ?? []))->toArray() : null;

        return view('movements.show', [
            'movement' => $movement->load('zone', 'membershipStatus'),
            'status' => $status,
            'latest' => $latest,
            'rows' => $latest !== null ? CategoryRows::for($latest) : [],
            'assessments' => $movement->assessments()->with(['score.band', 'artefacts.status', 'workItem'])->latest('assessed_on')->latest('id')->get()
                ->filter(fn (Assessment $a) => Gate::forUser($user)->allows('view', $a)),
            'assessors' => $user->isSecretariat() ? $movement->assessors()->where('active', true)->orderBy('name')->get() : collect(),
            'chair' => $movement->chair()->first(),
            'profile' => $profile,
            'assignmentHistory' => $assignmentHistory,
            'assignedSince' => $assignedSince,
            'canOpen' => Gate::forUser($user)->allows('openAssessment', $movement),
            'canAssign' => Gate::forUser($user)->allows('assignAssessors', $movement),
            'staff' => Gate::forUser($user)->allows('assignAssessors', $movement)
                ? User::query()->where('active', true)->where('role', '!=', Role::Board)->orderBy('name')->get()
                : collect(),
        ]);
    }

    /** The Administrators choose who assesses a movement. */
    public function assignAssessors(Request $request, Movement $movement, AssignAssessors $assign): RedirectResponse
    {
        $data = $request->validate(['assessor_ids' => ['array'], 'assessor_ids.*' => ['integer', 'exists:users,id']]);
        $assign->handle($request->user(), $movement, array_map('intval', $data['assessor_ids'] ?? []));

        return back()->with('status', 'Assessors saved.');
    }
}
