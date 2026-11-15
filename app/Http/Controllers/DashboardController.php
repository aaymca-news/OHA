<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\MovementStatus;
use App\Models\User;
use App\Models\Zone;
use App\Queries\DashboardAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Two dashboards, analytics only (decided 15 Nov 2026): nothing about work, tasks or what
 * is due; each person's work is in My Work, the timelines and the follow-ups. AAYMCA's
 * (staff and Administrators, all alike): the region's health and how it is changing, for
 * all zones or one. A National Movement's (its Board Chairperson and National General
 * Secretary, alike): its own score over the cycle and where it can grow, beside the region.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAnalytics $analytics): View
    {
        $user = $request->user();

        if (Gate::forUser($user)->denies('viewAllianceStats')) {
            return $this->movement($user, $analytics);
        }

        $zones = Zone::query()->orderBy('name')->get();
        $zone = $zones->firstWhere('id', $request->integer('zone'));

        return view('dashboard.secretariat', [
            'zones' => $zones,
            'zone' => $zone,
            'data' => $analytics->region($zone),
        ]);
    }

    /**
     * The movement's own dashboard: its health and how it has changed, its areas, its money
     * and people beside the region, and who to contact at AAYMCA.
     */
    private function movement(User $user, DashboardAnalytics $analytics): View
    {
        $movement = $user->movement;
        $status = MovementStatus::query()->with(['band', 'movement'])->findOrFail($user->movement_id);
        $gate = Gate::forUser($user);

        return view('dashboard.board', [
            'movement' => $movement,
            'status' => $status,
            'data' => $analytics->movement($status),
            'underWay' => $movement->assessmentUnderWay() !== null,
            'assessors' => $movement->activeAssessors()->get(),
            'assessments' => Assessment::query()->with('score.band')->where('movement_id', $movement->id)->latest('assessed_on')->latest('id')->get()
                ->filter(fn (Assessment $a) => $gate->allows('view', $a)),
        ]);
    }
}
