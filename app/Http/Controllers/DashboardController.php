<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\MovementStatus;
use App\Queries\AllianceInsights;
use App\Queries\AllianceStats;
use App\Queries\MyWork;
use App\Queries\OdpActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MyWork $myWork, AllianceStats $stats, AllianceInsights $insights, OdpActivity $odps): View
    {
        $user = $request->user();
        $work = collect($myWork->for($user))
            ->flatMap(fn (array $group) => collect($group['items'])->where('actionable', true))
            ->take(6);

        // Board members see their own movement only; no alliance-wide statistics.
        if (Gate::forUser($user)->denies('viewAllianceStats')) {
            return view('dashboard.board', [
                'status' => MovementStatus::query()->with(['band', 'movement'])->findOrFail($user->movement_id),
                'work' => $work,
                'assessments' => Assessment::query()->where('movement_id', $user->movement_id)->latest('assessed_on')->get()
                    ->filter(fn (Assessment $a) => Gate::forUser($user)->allows('view', $a)),
            ]);
        }

        return view('dashboard.secretariat', [
            'headline' => $stats->headline(),
            'bands' => $stats->bandDistribution(),
            'weakest' => $stats->weakestCategories(),
            'due' => $stats->reassessmentDue(),
            'work' => $work,
            'insights' => $insights,
            'pipeline' => $insights->pipeline(),
            'odpChanges' => $odps->latest($user),
            'odpSync' => $odps->sync(),
        ]);
    }
}
