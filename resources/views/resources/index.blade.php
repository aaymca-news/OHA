{{--
    Resources: the approved OHA form, report and ODP of each movement, one card per movement,
    with a link to its evidence library; then the documents movements have shared. A movement's
    own users see their own movement only. Expects $movements, $assessments (movement id =>
    list of [assessment, form, report, odp, signature]), $evidence (movement id => documents),
    $shared, $counts and $filters.
--}}
@php($secretariat = auth()->user()->isSecretariat())
<x-layouts.app :title="__('oha.nav.resources')"
               :subtitle="$secretariat
                    ? 'What the Administrators have approved, movement by movement: the OHA form, the report and the ODP, to read or download, and each movement’s evidence.'
                    : 'Your movement’s approved OHA form, report and ODP, to read or download, its evidence, and what other movements share.'">

    @php($here = fn (array $change = []) => route('resources', array_filter([...$filters, ...$change], fn ($v, $k) => $v !== null && $v !== '' && ! ($k === 'sort' && $v === 'movement') && ! ($k === 'dir' && $v === 'asc'), ARRAY_FILTER_USE_BOTH)))
    <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Find resources">
        @foreach (['show', 'view', 'sort', 'dir'] as $keep)
            @if ($filters[$keep] !== null && ! ($keep === 'sort' && $filters[$keep] === 'movement') && ! ($keep === 'dir' && $filters[$keep] === 'asc'))
                <input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">
            @endif
        @endforeach
        @if ($secretariat)
            <x-input name="q" type="search" label="Search" size="sm" :value="$filters['q']" placeholder="Movement or country" wrap="grow sm:grow-0" class="sm:w-48" />
            <x-select name="zone" label="Zone" size="sm" placeholder="All zones" :value="$filters['zone']" :options="$zones->pluck('name', 'id')->all()" />
            <x-select name="band" label="Health band" size="sm" placeholder="All bands" :value="$filters['band']" :options="$bands->pluck('label', 'code')->all()" />
        @endif
        <x-select name="year" label="Year" size="sm" placeholder="All years" :value="$filters['year']" :options="$years->combine($years)->all()" />
        <x-select name="odp" label="ODP" size="sm" placeholder="Any" :value="$filters['odp']" :options="['validated' => 'Validated', 'not_validated' => 'Approved, not validated']" />
        @if ($filters['view'] === 'table')
            <x-select name="type" label="Document" size="sm" placeholder="All types" :value="$filters['type']"
                      :options="['form' => 'OHA forms', 'report' => 'Reports', 'odp' => 'ODPs', 'evidence' => 'Evidence']" />
        @endif
        <x-button variant="secondary">Filter</x-button>
        @if ($filters['q'] !== '' || $filters['zone'] || $filters['band'] || $filters['year'] || $filters['odp'] || $filters['type'])
            <x-button variant="link" :href="route('resources', array_filter(['show' => $filters['show'], 'view' => $filters['view']]))" class="text-[0.875rem] pb-2">Clear</x-button>
        @endif

        <div class="ml-auto flex flex-wrap gap-sm">
            @if ($secretariat)
                <x-segmented label="Which movements" variant="joined">
                    @foreach ([null => 'With documents ('.$counts['with'].')', 'all' => 'All movements'] as $key => $label)
                        <x-segmented.link :href="$here(['show' => $key])" :active="$filters['show'] === ($key ?: null)">{{ $label }}</x-segmented.link>
                    @endforeach
                </x-segmented>
            @endif
            <x-segmented label="Show as" variant="joined">
                @foreach ([null => ['grid_view', 'Cards'], 'table' => ['table_rows', 'Table']] as $key => [$icon, $label])
                    <x-segmented.link :href="$here(['view' => $key, 'type' => $key ? $filters['type'] : null])" :active="$filters['view'] === ($key ?: null)" :icon="$icon">{{ $label }}</x-segmented.link>
                @endforeach
            </x-segmented>
        </div>
    </form>

    @if ($filters['view'] === 'table')
        @include('resources._table')
    @elseif ($movements->isEmpty())
        <x-empty-state icon="folder_open">
            @if ($filters['q'] !== '')
                No movement matches “{{ $filters['q'] }}”.
            @elseif ($filters['zone'] || $filters['band'] || $filters['year'] || $filters['odp'])
                Nothing matches these filters.
            @elseif ($secretariat)
                Nothing has been approved yet. Each movement’s OHA form, report and ODP appear here once an Administrator approves them.
            @else
                Nothing has been approved yet.
            @endif
        </x-empty-state>
    @else
        <div class="grid gap-md md:grid-cols-2 items-start">
            @foreach ($movements as $movement)
                @php($list = $assessments->get($movement->id, collect()))
                {{-- A soft YMCA-red glow when the pointer is on the card. --}}
                <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-sm transition duration-200 hover:border-secondary hover:shadow-[0_0_0_4px_var(--color-brand-red-wash),0_6px_18px_rgba(200,16,46,0.12)]" aria-labelledby="res-{{ $movement->id }}">
                    <header class="flex flex-wrap items-start gap-sm">
                        <div class="flex-1 min-w-[min(10rem,100%)]">
                            <h2 id="res-{{ $movement->id }}" class="text-[1rem] font-bold text-primary">
                                <a href="{{ route('movements.show', $movement) }}" class="underline decoration-1 underline-offset-2">{{ $movement->name }}</a>
                            </h2>
                            <p class="text-[0.8125rem] text-on-surface-variant">{{ $movement->city }}, {{ $movement->country }} · {{ $movement->zone->name }}</p>
                        </div>
                        @if ($list->first()['assessment']->score ?? null)
                            <x-band-chip :band="$list->first()['assessment']->score->band" />
                        @endif
                        @if ($list->isNotEmpty() || isset($evidence[$movement->id]))
                            <a href="{{ route('downloads.zip.movement', $movement) }}" class="inline-flex items-center gap-xs text-[0.8125rem] text-primary underline"
                               aria-label="Download everything of {{ $movement->name }} as a ZIP file">
                                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">folder_zip</span>All (ZIP)
                            </a>
                        @endif
                    </header>

                    @forelse ($list as $i => $r)
                        @php($a = $r['assessment'])
                        @if ($i === 1)
                            <details class="text-[0.875rem]">
                                <summary class="cursor-pointer text-primary font-semibold py-1">Earlier assessments ({{ $list->count() - 1 }})</summary>
                                <div class="flex flex-col gap-sm mt-sm">
                        @endif
                        <div class="flex flex-col gap-xs">
                            <p class="flex flex-wrap items-center gap-x-sm text-[0.8125rem] font-semibold text-on-surface-variant">
                                <span class="flex-1">
                                    <a href="{{ route('assessments.show', $a) }}" class="underline">{{ $a->period_label }}</a>
                                    @if ($a->score)
                                        · {{ \App\Support\Viz::figure((float) $a->score->points_achieved, 1) }} / {{ $a->score->points_available }} points
                                    @endif
                                </span>
                                <a href="{{ route('downloads.zip.assessment', $a) }}" class="inline-flex items-center gap-xs font-normal text-primary underline"
                                   aria-label="Download the {{ $a->period_label }} documents of {{ $movement->name }} as a ZIP file">
                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">folder_zip</span>ZIP
                                </a>
                            </p>
                            <ul class="flex flex-col divide-y divide-surface-container rounded border-[1.5px] border-outline-variant">
                                @foreach ([
                                    ['form', 'table_view', 'OHA form', $r['form'], $r['form'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'form', 'upload' => $r['form']->id]).'#form-preview' : null, $r['form'] ? route('downloads.form', $r['form']) : null],
                                    ['report', 'description', 'Report', $r['report'], $r['report'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'report', 'version' => $r['report']->id]) : null, $r['report'] ? route('downloads.document', $r['report']) : null],
                                    ['odp', 'checklist', 'ODP', $r['odp'], $r['odp'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'odp', 'version' => $r['odp']->id]) : null, $r['odp'] ? route('downloads.document', $r['odp']) : null],
                                ] as [$kind, $icon, $label, $file, $read, $download])
                                    <li class="flex flex-wrap items-center gap-sm px-sm py-2 text-[0.875rem]">
                                        <span class="material-symbols-outlined text-[1.25rem] {{ $file ? 'text-primary' : 'text-on-surface-variant' }}" aria-hidden="true">{{ $icon }}</span>
                                        <span class="flex-1 min-w-[min(10rem,100%)]">
                                            <span class="block font-semibold">{{ $label }}</span>
                                            @if ($file)
                                                <span class="block text-[0.8125rem] text-on-surface-variant break-words">
                                                    {{ $file->original_name }}{{ $kind !== 'form' ? ' · version '.$file->versionNumber() : '' }} · approved {{ $file->approved_at->asDay() }}
                                                </span>
                                            @else
                                                <span class="block text-[0.8125rem] text-on-surface-variant">Not approved yet</span>
                                            @endif
                                        </span>
                                        @if ($kind === 'odp' && $file)
                                            @if ($r['signature'])
                                                <x-chip tone="good" icon="verified">Validated · {{ $r['signature']->signed_at->asDay() }}</x-chip>
                                            @else
                                                <x-chip tone="neutral" icon="gpp_maybe">Not validated</x-chip>
                                            @endif
                                        @endif
                                        @if ($file)
                                            <span class="flex items-center gap-sm">
                                                <a href="{{ $read }}" class="text-primary underline">Read</a>
                                                <a href="{{ $download }}" class="inline-flex items-center gap-xs text-primary" aria-label="Download the {{ $label }} of {{ $movement->name }}, {{ $a->period_label }}">
                                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span><span class="underline">Download</span>
                                                </a>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @if ($i > 0 && $loop->last)
                                </div>
                            </details>
                        @endif
                    @empty
                        <x-empty-state icon="folder_open">Nothing approved yet.</x-empty-state>
                    @endforelse

                    {{-- The movement's evidence library: supporting documents linked to the form and the ODP. --}}
                    @php($held = (int) ($evidence[$movement->id] ?? 0))
                    <a href="{{ route('evidence.index', $movement) }}" class="flex flex-wrap items-center gap-sm px-sm py-2 rounded border-[1.5px] border-outline-variant text-[0.875rem] hover:border-primary">
                        <span class="material-symbols-outlined text-[1.25rem] text-primary" aria-hidden="true">folder_open</span>
                        <span class="flex-1 min-w-[min(10rem,100%)]">
                            <span class="block font-semibold text-primary">Evidence library</span>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $held === 0 ? 'No documents yet' : $held.' '.\Illuminate\Support\Str::plural('document', $held) }} · linked to the OHA form and the ODP</span>
                        </span>
                        <span class="material-symbols-outlined text-[1.25rem] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                    </a>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Documents movements have chosen to share: the document only, never the movement's scores or form. --}}
    <x-card :title="$secretariat ? 'Shared with all movements' : 'Shared by other movements'"
            subtitle="Supporting documents a movement has chosen to share, as examples to learn from.">
        @forelse ($shared->groupBy(fn ($doc) => $doc->category?->name ?? 'General') as $group => $docs)
            <div class="flex flex-col gap-xs text-[0.875rem]">
                <p class="text-[0.8125rem] font-semibold text-on-surface-variant">{{ $group }}</p>
                @foreach ($docs as $doc)
                    <p class="flex flex-wrap items-center gap-x-sm">
                        <x-evidence-link :doc="$doc" />
                        <span class="text-[0.8125rem] text-on-surface-variant">{{ $doc->assessment->movement->name }} · {{ $doc->describe() }}</span>
                    </p>
                @endforeach
            </div>
        @empty
            <x-empty-state icon="share">Nothing has been shared yet.</x-empty-state>
        @endforelse
    </x-card>
</x-layouts.app>
