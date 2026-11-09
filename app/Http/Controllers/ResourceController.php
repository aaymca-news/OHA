<?php

namespace App\Http\Controllers;

use App\Enums\ArtefactKind;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\HealthBand;
use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\User;
use App\Models\Zone;
use App\Queries\EvidenceLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Resources: what the Administrators have approved, movement by movement: the OHA
 * form, the report and the ODP of each assessment, to read or download, and each
 * movement's evidence library. Every AAYMCA staff member sees every movement; a
 * movement's Board Chairperson and National General Secretary see only their own.
 * Documents a movement has shared are listed for every movement.
 *
 * As cards (one per movement) or as a table (one row per document, sortable), filtered
 * by zone, health band, year and whether the ODP is validated; the table also by type.
 */
class ResourceController extends Controller
{
    /** The table's columns that sort, and what each sorts by. */
    private const SORTS = ['movement', 'zone', 'period', 'type', 'date'];

    public function __invoke(Request $request, EvidenceLibrary $library): View
    {
        $user = $request->user();
        $secretariat = $user->isSecretariat();
        $filters = [
            'q' => trim((string) $request->query('q')),
            'show' => $request->query('show') === 'all' ? 'all' : null,
            'zone' => $secretariat ? ($request->integer('zone') ?: null) : null,
            'band' => $secretariat && HealthBand::query()->where('code', $request->query('band'))->exists() ? (string) $request->query('band') : null,
            'year' => $request->integer('year') ?: null,
            'odp' => in_array($request->query('odp'), ['validated', 'not_validated'], true) ? (string) $request->query('odp') : null,
            'type' => in_array($request->query('type'), ['form', 'report', 'odp', 'evidence'], true) ? (string) $request->query('type') : null,
            'view' => $request->query('view') === 'table' ? 'table' : null,
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'movement',
            'dir' => $request->query('dir') === 'desc' ? 'desc' : 'asc',
        ];

        $movements = Movement::query()
            ->with('zone')
            ->when(! $secretariat, fn ($q) => $q->whereKey($user->movement_id))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($filters['q']).'%'])
                ->orWhereRaw('lower(country) like ?', ['%'.mb_strtolower($filters['q']).'%'])))
            ->when($filters['zone'], fn ($q, $zone) => $q->where('zone_id', $zone))
            ->when($filters['band'], fn ($q, $band) => $q->whereIn('id', MovementStatus::query()->where('band_code', $band)->select('movement_id')))
            ->orderBy('name')
            ->get();

        // Each movement's assessments with something approved, newest first, in the year and ODP state asked for.
        $assessments = Assessment::query()
            ->whereIn('movement_id', $movements->pluck('id'))
            ->when($filters['year'], fn ($q, $year) => $q->whereYear('assessed_on', $year))
            ->when($filters['odp'], fn (Builder $q, string $odp) => $q->whereHas('odp', fn ($a) => $a
                ->whereHas('status', fn ($s) => $s->where('published', true))
                ->when($odp === 'validated', fn ($s) => $s->whereHas('signature'), fn ($s) => $s->whereDoesntHave('signature'))))
            ->with([
                'score.band',
                'artefacts.status',
                'artefacts.signature',
                'artefacts.uploads' => fn ($q) => $q->whereNotNull('approved_at')->latest('id'),
                'artefacts.approvedVersion',
            ])
            ->latest('assessed_on')->latest('id')
            ->get()
            ->map(fn (Assessment $a) => $this->resources($a, $user))
            ->filter(fn (array $r) => $r['form'] !== null || $r['report'] !== null || $r['odp'] !== null)
            ->groupBy(fn (array $r) => $r['assessment']->movement_id);

        // How many documents each movement's evidence library holds, across its assessments.
        $evidence = Evidence::query()->join('assessments', 'assessments.id', '=', 'evidence.assessment_id')
            ->whereIn('assessments.movement_id', $movements->pluck('id'))
            ->when($filters['year'], fn ($q, $year) => $q->whereYear('assessments.assessed_on', $year))
            ->groupBy('assessments.movement_id')->selectRaw('assessments.movement_id, count(*) as documents')
            ->pluck('documents', 'movement_id');

        $narrowed = $filters['year'] !== null || $filters['odp'] !== null;
        $withDocuments = $movements->filter(fn (Movement $m) => $assessments->has($m->id) || (! $filters['odp'] && $evidence->has($m->id)));
        $shown = $secretariat && ($filters['show'] === null || $narrowed) ? $withDocuments : $movements;

        return view('resources.index', [
            'movements' => $shown,
            'assessments' => $assessments,
            'evidence' => $evidence,
            'rows' => $filters['view'] === 'table' ? $this->rows($shown, $assessments, $filters) : collect(),
            // A movement's own users see what the others share; AAYMCA staff see every library anyway.
            'shared' => $library->sharedBeyond($secretariat ? null : $user->movement_id),
            'counts' => ['with' => $withDocuments->count(), 'all' => $movements->count()],
            'filters' => $filters,
            'zones' => $secretariat ? Zone::query()->orderBy('name')->get() : collect(),
            'bands' => $secretariat ? HealthBand::query()->orderBy('sort_order')->get() : collect(),
            'years' => Assessment::query()->whereIn('movement_id', $secretariat ? Movement::query()->select('id') : [$user->movement_id])
                ->selectRaw('distinct extract(year from assessed_on)::int as year')->orderByDesc('year')->pluck('year'),
        ]);
    }

    /**
     * The approved form upload, and the approved versions of the report and ODP. The ODP
     * only when this user may see it: the National General Secretary, once it is signed.
     *
     * @return array{assessment: Assessment, form: mixed, report: mixed, odp: mixed, signature: mixed}
     */
    private function resources(Assessment $assessment, User $user): array
    {
        /** @var Collection<string, Artefact> $artefacts */
        $artefacts = $assessment->artefacts->keyBy(fn ($a) => $a->kind->value);
        $odp = $artefacts[ArtefactKind::Odp->value] ?? null;
        if ($odp !== null && Gate::forUser($user)->denies('view', $odp)) {
            $odp = null;
        }

        return [
            'assessment' => $assessment,
            'form' => ($artefacts[ArtefactKind::Form->value] ?? null)?->uploads->first(),
            'report' => ($artefacts[ArtefactKind::Report->value] ?? null)?->approvedVersion,
            'odp' => $odp?->approvedVersion,
            'signature' => $odp?->signature,
        ];
    }

    /**
     * One row per document for the table: the approved form, report and ODP of each
     * assessment, and its evidence, of the type asked for, sorted.
     *
     * @param  Collection<int, Movement>  $movements
     * @param  Collection<int, Collection<int, array{assessment: Assessment, form: mixed, report: mixed, odp: mixed, signature: mixed}>>  $assessments
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{movement: Movement, assessment: Assessment, type: string, kind: string, name: string, date: mixed, status: string, read: string|null, download: string}>
     */
    private function rows(Collection $movements, Collection $assessments, array $filters): Collection
    {
        $rows = collect();
        $byId = $movements->keyBy('id');

        foreach ($assessments as $movementId => $list) {
            $movement = $byId[$movementId] ?? null;
            if ($movement === null) {
                continue;
            }
            foreach ($list as $r) {
                $a = $r['assessment'];
                if ($r['form'] !== null) {
                    $rows->push(['movement' => $movement, 'assessment' => $a, 'type' => 'OHA form', 'kind' => 'form', 'name' => $r['form']->original_name,
                        'date' => $r['form']->approved_at, 'status' => 'Approved',
                        'read' => route('assessments.show', ['assessment' => $a, 'tab' => 'form', 'upload' => $r['form']->id]).'#form-preview',
                        'download' => route('downloads.form', $r['form'])]);
                }
                foreach (['report' => 'Report', 'odp' => 'ODP'] as $kind => $type) {
                    if ($r[$kind] !== null) {
                        $rows->push(['movement' => $movement, 'assessment' => $a, 'type' => $type, 'kind' => $kind, 'name' => $r[$kind]->original_name.' · version '.$r[$kind]->versionNumber(),
                            'date' => $r[$kind]->approved_at,
                            'status' => $kind === 'odp' ? ($r['signature'] ? 'Validated' : 'Not validated') : 'Approved',
                            'read' => route('assessments.show', ['assessment' => $a, 'tab' => $kind, 'version' => $r[$kind]->id]),
                            'download' => route('downloads.document', $r[$kind])]);
                    }
                }
            }
        }

        // Evidence of every assessment of these movements in the year asked for (not only approved ones): it is gathered while the work goes on.
        if ($filters['odp'] === null) {
            $evidence = Evidence::query()->with(['category', 'assessment'])
                ->whereHas('assessment', fn ($q) => $q->whereIn('movement_id', $movements->pluck('id'))
                    ->when($filters['year'], fn ($q, $year) => $q->whereYear('assessed_on', $year)))
                ->get()
                ->filter(fn (Evidence $e) => Gate::allows('download', $e));
            foreach ($evidence as $e) {
                $rows->push(['movement' => $byId[$e->assessment->movement_id], 'assessment' => $e->assessment, 'type' => 'Evidence', 'kind' => 'evidence',
                    'name' => $e->title.' · '.($e->category->name ?? 'General'), 'date' => $e->uploaded_at,
                    'status' => $e->isLink() ? 'Link' : 'File',
                    'read' => route('evidence.index', ['movement' => $byId[$e->assessment->movement_id], 'assessment' => $e->assessment_id]),
                    'download' => route('evidence.download', $e)]);
            }
        }

        if ($filters['type'] !== null) {
            $rows = $rows->where('kind', $filters['type']);
        }

        $key = match ($filters['sort']) {
            'zone' => fn (array $r) => $r['movement']->zone->name.' '.$r['movement']->name,
            'period' => fn (array $r) => $r['assessment']->assessed_on->format('Y-m-d').' '.$r['movement']->name,
            'type' => fn (array $r) => $r['type'].' '.$r['movement']->name,
            'date' => fn (array $r) => $r['date']?->format('Y-m-d H:i:s') ?? '',
            default => fn (array $r) => $r['movement']->name.' '.$r['assessment']->assessed_on->format('Y-m-d').' '.$r['type'],
        };

        return ($filters['dir'] === 'desc' ? $rows->sortByDesc($key) : $rows->sortBy($key))->values();
    }
}
