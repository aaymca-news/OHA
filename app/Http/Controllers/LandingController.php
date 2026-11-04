<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\HealthBand;
use Illuminate\View\View;

/**
 * The public front page: what the Organisational Health Assessment is and why it
 * matters. It shows the nine areas and the names of the health bands, never a movement,
 * a score, a count, a role or a step of the workflow. Signed-in users go straight to
 * their dashboard.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            'areas' => Category::query()->orderBy('form_order')->get(['code', 'name']),
            // The five bands, by name and colour only: no thresholds, no timetable. "Not started" is not one of them.
            'bands' => HealthBand::query()->where('min_pct', '>', 0)->orderBy('sort_order')->get(['code', 'label', 'color', 'sort_order']),
        ]);
    }
}
