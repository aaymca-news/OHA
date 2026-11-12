<?php

namespace App\Http\Controllers;

use App\Queries\Journeys;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The Alliance's journeys, for AAYMCA staff: each movement's latest cycle, from Baseline 1
 * to its newest baseline (decided 12 Nov 2026).
 */
class JourneyController extends Controller
{
    public function __invoke(Journeys $journeys): View
    {
        Gate::authorize('viewAllianceStats');

        return view('journeys.index', ['journeys' => $journeys->alliance()]);
    }
}
