<?php

namespace App\Http\Controllers;

use App\Actions\Oha\DeleteAssessment;
use App\Actions\Oha\HandBackAssessment;
use App\Actions\Oha\OpenAssessment;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Queries\AssessmentPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AssessmentController extends Controller
{
    /**
     * The Administrators' page for handing out the work: who assesses each National
     * Movement (one or more staff, changed at any time), and every assessment, with
     * who holds it now and when it is due.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Assessment::class);

        // Every assessment, newest first, a page at a time (the list grows every year).
        $assessments = Assessment::query()
            ->with(['movement', 'score.band', 'workItem', 'artefacts.status', 'opener'])
            ->latest('opened_at')->latest('id')
            ->paginate(config('oha.per_page'))->withQueryString();
        // Each movement's assessment under way, if it has one.
        $underWay = Assessment::query()->underWay()->with('workItem')->get()->keyBy('movement_id');

        $movements = Movement::query()
            ->with(['zone', 'assessors' => fn ($q) => $q->where('active', true)->orderBy('name')])
            ->withCount('assessments')
            ->orderBy('name')->get();
        $needing = $movements->filter(fn (Movement $m) => $m->assessors->isEmpty() && ($underWay->has($m->id) || $m->assessments_count === 0));
        $show = $request->query('show') === 'unassigned' ? 'unassigned' : 'all';

        return view('assessments.index', [
            'assessments' => $assessments,
            'movements' => $show === 'unassigned' ? $needing->values() : $movements,
            'needing' => $needing->count(),
            'total' => $movements->count(),
            'show' => $show,
            'underWay' => $underWay,
            'assessorsOf' => $movements->mapWithKeys(fn (Movement $m) => [$m->id => $m->assessors]),
            'staff' => User::query()->where('active', true)->whereNotIn('role', [Role::Board, Role::Ngs])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Movement $movement, OpenAssessment $open): RedirectResponse
    {
        $data = $request->validate([
            'period_label' => ['required', 'string', 'max:40'],
            'period_start' => ['required', 'date_format:Y-m'],
        ]);

        $assessment = $open->handle($request->user(), $movement, $data['period_label'], Carbon::createFromFormat('!Y-m', $data['period_start']));

        return redirect()->route('assessments.show', $assessment)->with('status', 'Assessment opened. Upload the completed OHA form to begin.');
    }

    /** An assessor stops working on the assessment; the Administrators are asked to reassign it. */
    public function handBack(Request $request, Assessment $assessment, HandBackAssessment $handBack): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Say why you are stopping.']);
        $handBack->handle($assessment, $request->user(), $data['reason']);

        return redirect()->route('movements.show', $assessment->movement)
            ->with('status', 'You no longer work on this assessment. The Administrators have been asked to assign an assessor.');
    }

    /** The Super Administrator deletes the whole assessment. */
    public function destroy(Request $request, Assessment $assessment, DeleteAssessment $delete): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $movement = $assessment->movement;
        $delete->handle($assessment, $request->user(), $data['confirm_name'], $data['reason']);

        return redirect()->route('movements.show', $movement)->with('status', "The {$movement->name} assessment ({$assessment->period_label}) has been deleted.");
    }

    public function show(Request $request, Assessment $assessment, AssessmentPage $page): View
    {
        Gate::authorize('view', $assessment);

        return view('assessments.show', $page->for($request->user(), $assessment, [
            'tab' => $request->query('tab'),
            'view' => $request->query('view'),
            'who' => $request->integer('who') ?: null,
            'upload' => $request->integer('upload') ?: null,
        ]));
    }
}
