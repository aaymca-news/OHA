{{--
    Resources, in the site's newer style (design standard of 10 Nov 2026): the approved OHA
    form, report and ODP of each movement, one card per movement, with a link to its evidence
    library; then the documents movements have shared. A movement's own users see their own
    movement only. Expects $movements, $assessments (movement id => list of [assessment, form,
    report, odp, signature]), $evidence (movement id => documents), $shared, $counts and $filters.
--}}
@use('App\Support\Bands')
@php($secretariat = auth()->user()->isSecretariat())
<x-layouts.app :title="__('oha.nav.resources')"
               :subtitle="$secretariat
                    ? 'What the Administrators have approved, movement by movement: the OHA form, the report and the ODP, to read or download, and each movement’s evidence.'
                    : 'Your movement’s approved OHA form, report and ODP, to read or download, its evidence, and what other movements share.'">

    @php($here = fn (array $change = []) => route('resources', array_filter([...$filters, ...$change], fn ($v, $k) => $v !== null && $v !== '' && ! ($k === 'sort' && $v === 'movement') && ! ($k === 'dir' && $v === 'asc'), ARRAY_FILTER_USE_BOTH)))

    {{-- One light filter bar, the choices of view at its right --}}
    <x-panel class="!p-md">
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
            <x-button variant="outline" size="sm">Filter</x-button>
            @if ($filters['q'] !== '' || $filters['zone'] || $filters['band'] || $filters['year'] || $filters['odp'] || $filters['type'])
                <x-button variant="link" :href="route('resources', array_filter(['show' => $filters['show'], 'view' => $filters['view']]))" class="text-[0.84375rem] pb-2">Clear</x-button>
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
    </x-panel>

    @if ($filters['view'] === 'table')
        @include('resources._table')
    @elseif ($movements->isEmpty())
        <x-panel>
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
        </x-panel>
    @else
        <div class="grid gap-md md:grid-cols-2 items-start">
            @foreach ($movements as $movement)
                {{-- One-line @php only in this file: Blade misreads a @php block after a one-line @php(…). --}}
                @php($list = $assessments->get($movement->id, collect()))
                @php($latestScore = $list->first()['assessment']->score ?? null)
                @php($held = (int) ($evidence[$movement->id] ?? 0))
                <section class="flex flex-col gap-md rounded-2xl border border-[#e7e5e0] bg-surface-container-lowest p-lg shadow-[0_1px_2px_rgba(20,20,20,0.04)]" aria-labelledby="res-{{ $movement->id }}">
                    <header class="flex flex-wrap items-start gap-sm">
                        <div class="flex-1 min-w-[min(10rem,100%)]">
                            <h2 id="res-{{ $movement->id }}" class="text-[1.0625rem] font-bold">
                                <a href="{{ route('movements.show', $movement) }}" class="hover:underline">{{ $movement->name }}</a>
                            </h2>
                            <p class="text-[0.8125rem] text-on-surface-variant">{{ $movement->city }}, {{ $movement->country }} · {{ $movement->zone->name }}</p>
                        </div>
                        @if ($latestScore)
                            <x-status-pill :tone="Bands::tone($latestScore->band->code)">{{ $latestScore->band->label }}</x-status-pill>
                        @endif
                        @if ($list->isNotEmpty() || isset($evidence[$movement->id]))
                            <a href="{{ route('downloads.zip.movement', $movement) }}" class="inline-flex items-center gap-[6px] min-h-9 rounded-[10px] border border-[#e7e5e0] px-[12px] text-[0.8125rem] font-semibold hover:bg-surface-container-low"
                               aria-label="Download everything of {{ $movement->name }} as a ZIP file">
                                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">folder_zip</span>All (ZIP)
                            </a>
                        @endif
                    </header>

                    @forelse ($list as $i => $r)
                        @php($a = $r['assessment'])
                        @if ($i === 1)
                            <details class="text-[0.875rem]">
                                <summary class="cursor-pointer font-semibold py-1">Earlier assessments ({{ $list->count() - 1 }})</summary>
                                <div class="flex flex-col gap-md mt-sm">
                        @endif
                        <div class="flex flex-col gap-sm">
                            <p class="flex flex-wrap items-center gap-x-sm text-[0.8125rem]">
                                <span class="flex-1">
                                    <a href="{{ route('assessments.show', $a) }}" class="font-semibold hover:underline">{{ $a->period_label }}</a>
                                    @if ($a->score)
                                        <span class="text-on-surface-variant">· {{ \App\Support\Viz::figure((float) $a->score->points_achieved, 1) }} / {{ $a->score->points_available }} points</span>
                                    @endif
                                </span>
                                <a href="{{ route('downloads.zip.assessment', $a) }}" class="inline-flex items-center gap-[4px] font-semibold text-on-surface-variant hover:text-on-surface"
                                   aria-label="Download the {{ $a->period_label }} documents of {{ $movement->name }} as a ZIP file">
                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">folder_zip</span>ZIP
                                </a>
                            </p>
                            <ul class="flex flex-col rounded-xl border border-[#ebe9e4]">
                                @foreach ([
                                    ['form', 'table_view', 'OHA form', $r['form'], $r['form'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'form', 'upload' => $r['form']->id]).'#form-preview' : null, $r['form'] ? route('downloads.form', $r['form']) : null],
                                    ['report', 'description', 'Report', $r['report'], $r['report'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'report', 'version' => $r['report']->id]) : null, $r['report'] ? route('downloads.document', $r['report']) : null],
                                    ['odp', 'checklist', 'ODP', $r['odp'], $r['odp'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'odp', 'version' => $r['odp']->id]) : null, $r['odp'] ? route('downloads.document', $r['odp']) : null],
                                ] as [$kind, $icon, $label, $file, $read, $download])
                                    <li class="flex flex-wrap items-center gap-sm px-sm py-[10px] text-[0.875rem] border-t border-[#f2f1ec] first:border-0">
                                        <span @class(['grid size-9 flex-none place-items-center rounded-[10px]', 'bg-[#f2f1ee] text-on-surface' => $file, 'bg-[#f7f6f3] text-on-surface-variant' => ! $file]) aria-hidden="true">
                                            <span class="material-symbols-outlined text-[1.125rem]">{{ $icon }}</span>
                                        </span>
                                        <span class="flex-1 min-w-[min(10rem,100%)]">
                                            <span class="block font-semibold">{{ $label }}</span>
                                            @if ($file)
                                                <span class="block text-[0.78125rem] text-on-surface-variant break-words">
                                                    {{ $file->original_name }}{{ $kind !== 'form' ? ' · version '.$file->versionNumber() : '' }} · approved {{ $file->approved_at->asDay() }}
                                                </span>
                                            @else
                                                <span class="block text-[0.78125rem] text-on-surface-variant">Not approved yet</span>
                                            @endif
                                        </span>
                                        @if ($kind === 'odp' && $file)
                                            @if ($r['signature'])
                                                <x-status-pill tone="good">Validated · {{ $r['signature']->signed_at->asDay() }}</x-status-pill>
                                            @else
                                                <x-status-pill>Not validated</x-status-pill>
                                            @endif
                                        @endif
                                        @if ($file)
                                            <span class="flex items-center gap-xs">
                                                <a href="{{ $read }}" class="inline-flex items-center min-h-8 rounded-[8px] px-[10px] text-[0.8125rem] font-semibold hover:bg-surface-container-low">Read</a>
                                                <a href="{{ $download }}" class="grid size-8 place-items-center rounded-[8px] text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface" title="Download" aria-label="Download the {{ $label }} of {{ $movement->name }}, {{ $a->period_label }}">
                                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span>
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
                    <a href="{{ route('evidence.index', $movement) }}" class="mt-auto flex flex-wrap items-center gap-sm rounded-xl bg-[#f7f6f3] px-sm py-[10px] text-[0.875rem] hover:bg-[#f0eeea]">
                        <span class="grid size-9 flex-none place-items-center rounded-[10px] bg-surface-container-lowest" aria-hidden="true">
                            <span class="material-symbols-outlined text-[1.125rem]">folder_open</span>
                        </span>
                        <span class="flex-1 min-w-[min(10rem,100%)]">
                            <span class="block font-semibold">Evidence library</span>
                            <span class="block text-[0.78125rem] text-on-surface-variant">{{ $held === 0 ? 'No documents yet' : $held.' '.\Illuminate\Support\Str::plural('document', $held) }} · linked to the OHA form and the ODP</span>
                        </span>
                        <span class="material-symbols-outlined text-[1.25rem] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                    </a>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Documents movements have chosen to share: the document only, never the movement's scores or form. --}}
    <x-panel stack :title="$secretariat ? 'Shared with all movements' : 'Shared by other movements'"
             subtitle="Supporting documents a movement has chosen to share, as examples to learn from.">
        @forelse ($shared->groupBy(fn ($doc) => $doc->category?->name ?? 'General') as $group => $docs)
            <div class="flex flex-col gap-xs text-[0.875rem]">
                <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $group }}</p>
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
    </x-panel>
</x-layouts.app>
