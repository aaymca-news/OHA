<?php

namespace App\Http\Controllers;

use App\Actions\Oha\AssignAssessors;
use App\Enums\Stage;
use App\Models\Assessment;
use App\Models\HealthBand;
use App\Models\MembershipStatus;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\Zone;
use App\Queries\AllianceInsights;
use App\Queries\MovementPage;
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

    /**
     * One movement's page: its dashboard for everyone who may see it, and for AAYMCA staff
     * the Manage section beneath (see App\Queries\MovementPage).
     */
    public function show(Request $request, Movement $movement, MovementPage $page): View
    {
        Gate::authorize('view', $movement);

        return view('movements.show', $page->for($request->user(), $movement));
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
