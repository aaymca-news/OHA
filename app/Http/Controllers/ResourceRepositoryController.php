<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Evidence;
use App\Queries\EvidenceLibrary;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Area Resource Repository (decided 27 Nov 2026): every piece of evidence shared from a
 * National Movement's Health Assessment Evidence Library, by area, with the movement it
 * came from, for everyone (AAYMCA staff and every movement's own users). Sharing is decided
 * in the library, by the movement's National General Secretary and AAYMCA staff.
 */
class ResourceRepositoryController extends Controller
{
    public function __invoke(Request $request, EvidenceLibrary $library): View
    {
        // The nine OHA areas in the form's order, then General.
        $areas = Category::query()->orderBy('form_order')->pluck('name', 'code')->all() + ['general' => 'General'];
        $everything = $library->shared();
        $movements = $everything->map(fn (Evidence $e) => $e->assessment->movement)->unique('id')->sortBy('name')->values();

        $filters = [
            'q' => trim((string) $request->query('q')),
            'area' => array_key_exists((string) $request->query('area'), $areas) ? (string) $request->query('area') : null,
            'movement' => $movements->contains('id', $request->integer('movement')) ? $request->integer('movement') : null,
        ];
        $filtered = $filters['q'] !== '' || $filters['area'] !== null || $filters['movement'] !== null;
        $documents = $filtered ? $library->shared($filters['area'], $filters['movement'], $filters['q']) : $everything;

        // Each area holding something, in the form's order.
        $byArea = $documents->groupBy(fn (Evidence $e) => $e->category->code ?? 'general');
        $groups = collect($areas)->filter(fn (string $name, string $code) => $byArea->has($code))
            ->map(fn (string $name, string $code) => ['name' => $name, 'documents' => $byArea[$code]]);

        return view('resource-repository.index', [
            'groups' => $groups,
            'count' => $documents->count(),
            'total' => $everything->count(),
            'sharers' => $movements->count(),
            'areas' => $areas,
            'movements' => $movements,
            'filters' => $filters,
            'filtered' => $filtered,
        ]);
    }
}
