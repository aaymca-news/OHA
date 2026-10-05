<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Movement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Find a movement or an assessment by name, country or city. Board members only
 * ever find their own movement.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q'));

        $movements = collect();
        $assessments = collect();

        if (mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

            $movements = Movement::query()
                ->where(fn ($w) => $w->whereLike('name', $like, caseSensitive: false)
                    ->orWhereLike('country', $like, caseSensitive: false)
                    ->orWhereLike('city', $like, caseSensitive: false))
                ->when(! $user->isSecretariat(), fn ($w) => $w->whereKey($user->movement_id))
                ->orderBy('name')->limit(20)->get();

            $assessments = Assessment::query()->with(['movement', 'workItem'])
                ->whereIn('movement_id', $movements->pluck('id'))
                ->latest('assessed_on')->limit(20)->get()
                ->filter(fn (Assessment $a) => Gate::forUser($user)->allows('view', $a));
        }

        return view('search', ['q' => $q, 'movements' => $movements, 'assessments' => $assessments]);
    }
}
