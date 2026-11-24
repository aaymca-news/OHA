<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use App\Queries\DashboardAnalytics;
use App\Queries\MovementPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Two dashboards, analytics only (decided 15 Nov 2026): nothing about work, tasks or what
 * is due; each person's work is in My Work, the timelines and the follow-ups. AAYMCA's
 * (staff and Administrators, all alike): the region's health and how it is changing, for
 * all zones or one. A National Movement's (its Board Chairperson and National General
 * Secretary, alike): since 23 Nov 2026, its movement's page, the very one AAYMCA staff see
 * (without the Manage section they have beneath it).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAnalytics $analytics, MovementPage $page): View
    {
        $user = $request->user();

        if (Gate::forUser($user)->denies('viewAllianceStats')) {
            abort_if($user->movement === null, 404);

            return view('movements.show', $page->for($user, $user->movement));
        }

        $zones = Zone::query()->orderBy('name')->get();
        $zone = $zones->firstWhere('id', $request->integer('zone'));

        return view('dashboard.secretariat', [
            'zones' => $zones,
            'zone' => $zone,
            'data' => $analytics->region($zone),
        ]);
    }
}
