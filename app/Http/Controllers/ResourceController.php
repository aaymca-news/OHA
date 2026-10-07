<?php

namespace App\Http\Controllers;

use App\Enums\ArtefactKind;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Movement;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Resources: what the Administrators have approved, movement by movement: the OHA
 * form, the report and the ODP of each assessment, to read or download. Every AAYMCA
 * staff member sees every movement; a Board Chairperson sees only their own.
 */
class ResourceController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('q'));

        $movements = Movement::query()
            ->with('zone')
            ->when(! $user->isSecretariat(), fn ($q) => $q->whereKey($user->movement_id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('lower(country) like ?', ['%'.mb_strtolower($search).'%'])))
            ->orderBy('name')
            ->get();

        // Each movement's assessments with something approved, newest first.
        $assessments = Assessment::query()
            ->whereIn('movement_id', $movements->pluck('id'))
            ->with([
                'score.band',
                'artefacts.status',
                'artefacts.signature',
                'artefacts.uploads' => fn ($q) => $q->whereNotNull('approved_at')->latest('id'),
                'artefacts.approvedVersion',
            ])
            ->latest('opened_at')->latest('id')
            ->get()
            ->map(fn (Assessment $a) => $this->resources($a))
            ->filter(fn (array $r) => $r['form'] !== null || $r['report'] !== null || $r['odp'] !== null)
            ->groupBy(fn (array $r) => $r['assessment']->movement_id);

        $withDocuments = $movements->filter(fn (Movement $m) => $assessments->has($m->id));
        $only = $request->query('show') !== 'all';

        return view('resources.index', [
            'movements' => $only && $user->isSecretariat() ? $withDocuments : $movements,
            'assessments' => $assessments,
            'counts' => ['with' => $withDocuments->count(), 'all' => $movements->count()],
            'filters' => ['q' => $search, 'show' => $only ? null : 'all'],
        ]);
    }

    /**
     * The approved form upload, and the approved versions of the report and ODP.
     *
     * @return array{assessment: Assessment, form: mixed, report: mixed, odp: mixed, signature: mixed}
     */
    private function resources(Assessment $assessment): array
    {
        /** @var Collection<string, Artefact> $artefacts */
        $artefacts = $assessment->artefacts->keyBy(fn ($a) => $a->kind->value);
        $odp = $artefacts[ArtefactKind::Odp->value] ?? null;

        return [
            'assessment' => $assessment,
            'form' => ($artefacts[ArtefactKind::Form->value] ?? null)?->uploads->first(),
            'report' => ($artefacts[ArtefactKind::Report->value] ?? null)?->approvedVersion,
            'odp' => $odp?->approvedVersion,
            'signature' => $odp?->signature,
        ];
    }
}
