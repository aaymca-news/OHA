@use('App\Support\Bands')
@use('App\Support\Viz')
{{--
    Every National Movement (AAYMCA staff), in the site's newer style (design standard of
    10 Nov 2026): the downloads and "Add" at the top right; Stage 1 across the region; one
    light filter bar; then the movements as cards grouped by zone (score ring, band, the
    eight categories as a strip, its stage, next assessment and assessors), or as a table.
--}}
@php
    $me = auth()->user();
    // $stage($status) gives an App\Enums\Stage: its label, icon and tone.
    $started = fn ($status) => ! in_array($stage($status), [\App\Enums\Stage::NotAssessed, \App\Enums\Stage::Complete, \App\Enums\Stage::Closed], true);
    $short = ['financial' => 'Financial', 'governance' => 'Governance', 'constitution' => 'Constitution', 'me' => 'M&E', 'strategy' => 'Strategy',
        'diversity' => 'Diversity & youth', 'comms' => 'Comms', 'staff' => 'Staff & volunteers'];
    $query = fn (array $change) => route('movements.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
    // When the next assessment comes: no band-based date (decided 12 Nov 2026), it starts once the cycle is closed.
    $next = function ($status) use ($stage) {
        [$text, $tone] = $stage($status)->nextAssessment($status->next_assessment_on);

        return ['text' => $text, 'late' => $tone === 'critical' && ! $status->movement->followUpPaused()];
    };
    $filtered = array_filter(\Illuminate\Support\Arr::except($filters, 'view'));
    $label = 'text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant';
@endphp
<x-layouts.app :title="__('oha.nav.movements')" :subtitle="'All '.$pipeline['total'].' National Movements. A movement is rated once one of its OHA forms is approved.'">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            <details class="relative">
                <summary class="list-none inline-flex items-center gap-[6px] min-h-[40px] rounded-[10px] border border-[#e7e5e0] bg-surface-container-lowest px-[14px] text-[0.84375rem] font-semibold cursor-pointer hover:bg-surface-container-low">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span>Scores for reporting (CSV)
                </summary>
                <div class="absolute right-0 z-20 mt-xs w-64 rounded-xl border border-[#e7e5e0] bg-surface-container-lowest p-xs shadow-[0_12px_32px_rgba(20,20,20,0.12)]">
                    <a href="{{ route('downloads.scores') }}" class="block rounded-lg px-sm py-[8px] text-[0.875rem] hover:bg-surface-container-low">Latest, per movement</a>
                    <a href="{{ route('downloads.scores', ['all' => 1]) }}" class="block rounded-lg px-sm py-[8px] text-[0.875rem] hover:bg-surface-container-low">Every approved assessment</a>
                </div>
            </details>
            @can('create', \App\Models\Movement::class)
                <a href="{{ route('admin.movements.create') }}" class="inline-flex items-center gap-[6px] min-h-[40px] rounded-[10px] bg-on-surface px-[14px] text-[0.84375rem] font-semibold text-white hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">add</span>Add a National Movement
                </a>
            @endcan
        </div>
    </x-slot:actions>

    <x-panel title="Stage 1 across the region" :subtitle="'Where each of the '.$pipeline['total'].' National Movements is: from not yet assessed to a signed ODP.'">
        <x-viz.pipeline :pipeline="$pipeline" />
    </x-panel>

    {{-- One light filter bar --}}
    <x-panel class="!p-md">
        <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Filter movements">
            <x-input type="search" name="q" label="Search" size="sm" :value="$filters['q'] ?? ''" placeholder="Name, city or country"
                     wrap="grow sm:grow-0" class="sm:w-48" />
            @foreach ([['zone', 'Zone', $zones, 'code', 'name'], ['membership', 'Membership', $memberships, 'code', 'label'], ['band', 'Band', $bands, 'code', 'label']] as [$name, $text, $options, $value, $shown])
                <x-select :name="$name" :label="$text" size="sm" placeholder="All" :value="$filters[$name] ?? ''"
                          :options="collect($options)->mapWithKeys(fn ($option) => [$option->{$value} => $option->{$shown}])->all()" />
            @endforeach
            <x-select name="stage" label="Stage" size="sm" placeholder="All" :value="$filters['stage'] ?? ''"
                      :options="collect(\App\Enums\Stage::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
            <input type="hidden" name="view" value="{{ $view }}">
            <button type="submit" class="inline-flex items-center min-h-[36px] rounded-[10px] border border-on-surface bg-surface-container-lowest px-[14px] text-[0.84375rem] font-semibold hover:bg-surface-container-low">Filter</button>
            @if ($filtered)
                <a href="{{ route('movements.index', ['view' => $view]) }}" class="pb-[8px] text-[0.84375rem] font-semibold text-on-surface-variant underline hover:text-on-surface">Clear</a>
            @endif
            <div role="group" aria-label="View" class="ml-auto flex gap-xs rounded-full bg-[#f2f1ee] p-[3px]">
                @foreach (['cards' => ['Cards', 'grid_view'], 'table' => ['Table', 'table_rows']] as $key => [$text, $icon])
                    <a href="{{ $query(['view' => $key]) }}" @if ($view === $key) aria-current="page" @endif
                       @class([
                           'inline-flex items-center gap-[6px] min-h-[32px] rounded-full px-[12px] text-[0.8125rem] font-semibold',
                           'bg-surface-container-lowest text-on-surface shadow-[0_1px_2px_rgba(20,20,20,0.08)]' => $view === $key,
                           'text-on-surface-variant hover:text-on-surface' => $view !== $key,
                       ])>
                        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $icon }}</span>{{ $text }}
                    </a>
                @endforeach
            </div>
        </form>
    </x-panel>

    @if ($statuses->isEmpty())
        <x-panel><x-empty-state icon="filter_alt_off">No movement matches these filters.</x-empty-state></x-panel>
    @elseif ($view === 'cards')
        @foreach ($statuses->groupBy(fn ($s) => $s->movement->zone->name) as $zone => $group)
            <section class="flex flex-col gap-sm" aria-labelledby="zone-{{ \Illuminate\Support\Str::slug($zone) }}">
                <h2 id="zone-{{ \Illuminate\Support\Str::slug($zone) }}" class="flex flex-wrap items-baseline gap-x-sm">
                    <span class="text-[1rem] font-bold">{{ $zone }}</span>
                    <span class="text-[0.8125rem] text-on-surface-variant">{{ $group->count() }} {{ $group->count() === 1 ? 'movement' : 'movements' }} · {{ $group->whereNotNull('pct')->count() }} rated</span>
                </h2>
                <ul class="grid sm:grid-cols-2 xl:grid-cols-3 gap-md">
                    @foreach ($group as $status)
                        @php
                            $m = $status->movement;
                            $at = $stage($status);
                            $who = $assessors[$m->id] ?? collect();
                            $mine = $cells[$m->id] ?? null;
                            $when = $next($status);
                        @endphp
                        <li class="relative flex flex-col gap-md rounded-2xl border border-[#e7e5e0] bg-surface-container-lowest p-[18px] shadow-[0_1px_2px_rgba(20,20,20,0.04)] transition hover:border-[#cfccc4] hover:shadow-[0_8px_24px_rgba(20,20,20,0.08)]">
                            <div class="flex items-start gap-md">
                                <div class="flex-1 min-w-0">
                                    {{-- The whole card opens the movement. --}}
                                    <a href="{{ route('movements.show', $m) }}" class="text-[1rem] font-bold text-on-surface after:absolute after:inset-0 after:rounded-2xl focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-primary">{{ $m->name }}</a>
                                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $m->city }} · {{ $m->country }} · {{ $m->membershipStatus->label }}</p>
                                </div>
                                <x-viz.ring :pct="$status->pct" :band="$status->band" :size="56" />
                            </div>

                            @if ($mine)
                                <div class="flex flex-col gap-[6px]">
                                    <div class="flex flex-wrap items-center gap-sm">
                                        <x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill>
                                        <span class="text-[0.78125rem] text-on-surface-variant">{{ Viz::figure((float) $status->points_achieved) }} of {{ $status->points_available }} points · {{ $status->last_assessed_on?->asMonth() }}</span>
                                    </div>
                                    <div class="relative z-[1] grid grid-cols-8 gap-[3px]" role="img" aria-label="Category scores: {{ $categories->map(fn ($c) => ($short[$c->code] ?? $c->name).' '.(isset($mine[$c->code]) ? round($mine[$c->code]).'%' : 'not rated'))->join(', ') }}">
                                        @foreach ($categories as $c)
                                            {{-- Each category in its band's colour, as on the score bars. --}}
                                            @php($b = isset($mine[$c->code]) ? Bands::at((float) $mine[$c->code]) : null)
                                            <span class="h-[6px] rounded-full {{ $b ? Bands::fill($b['code']) : 'bg-[#e9e7e2]' }}" title="{{ $c->name }}: {{ $b ? round($mine[$c->code]).'% · '.$b['label'] : 'not rated' }}"></span>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-sm">
                                    @if ($started($status))
                                        <x-status-pill tone="info">Assessment started</x-status-pill>
                                    @endif
                                    <span class="text-[0.78125rem] text-on-surface-variant">No approved OHA form yet{{ $m->planned_assessment_label ? ' · planned '.$m->planned_assessment_label : '' }}</span>
                                </div>
                            @endif

                            <div class="mt-auto flex flex-col gap-sm border-t border-[#f0eeea] pt-md">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <x-status-pill :tone="$at->tone()">{{ $at->label() }}</x-status-pill>
                                    <span @class(['text-[0.78125rem]', 'font-semibold text-critical-ink' => $when['late'], 'text-on-surface-variant' => ! $when['late']])>Next assessment: {{ \Illuminate\Support\Str::lcfirst($when['text']) }}</span>
                                </div>
                                @if ($me->isSecretariat())
                                    @if ($who->isEmpty())
                                        <span @class(['inline-flex items-center gap-[6px] text-[0.78125rem]', 'font-semibold text-serious-ink' => $me->isAdmin(), 'text-on-surface-variant' => ! $me->isAdmin()])>
                                            <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">person_off</span>No assessor
                                        </span>
                                    @else
                                        <span class="flex items-center gap-sm min-w-0 text-[0.78125rem] text-on-surface-variant" title="Assessed by {{ $who->pluck('name')->join(', ') }}">
                                            <span class="flex -space-x-1.5 shrink-0">
                                                @foreach ($who->take(3) as $person)
                                                    <x-avatar :user="$person" size="sm" class="ring-2 ring-surface-container-lowest" />
                                                @endforeach
                                            </span>
                                            <span class="truncate">{{ $who->pluck('name')->join(', ') }}</span>
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @else
        <x-panel flush>
            <x-table bare>
                <x-slot:head>
                    <tr>
                        <th>Movement</th>
                        <th>Zone</th>
                        <th>Membership</th>
                        <th>Stage</th>
                        <th class="w-56">Score</th>
                        <th>Band</th>
                        <th>Next assessment</th>
                    </tr>
                </x-slot:head>
                @foreach ($statuses as $status)
                    @php($at = $stage($status))
                    @php($when = $next($status))
                    <tr class="border-t border-[#f2f1ec] hover:bg-[#fafaf8]">
                        <td><a href="{{ route('movements.show', $status->movement) }}" class="font-semibold text-on-surface hover:underline">{{ $status->name }}</a>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $status->movement->city }}</span></td>
                        <td>{{ $status->movement->zone->name }}</td>
                        <td>{{ $status->movement->membershipStatus->label }}</td>
                        <td><x-status-pill :tone="$at->tone()">{{ $at->label() }}</x-status-pill></td>
                        <td>
                            @if ($status->pct !== null)
                                <span class="flex items-center gap-sm">
                                    <span class="relative h-2 flex-1 min-w-[5rem] rounded-full bg-[#f0eeea]" title="{{ Viz::points($status->points_achieved, $status->points_available, '/') }}">
                                        <span class="absolute inset-y-0 left-0 rounded-full {{ Bands::fill($status->band_code) }}" style="width: {{ max(1.5, min(100, (float) $status->pct)) }}%"></span>
                                    </span>
                                    <span class="font-bold tabular-nums">{{ Viz::pct($status->pct) }}</span>
                                </span>
                            @else
                                <span class="text-on-surface-variant">Not rated</span>
                            @endif
                        </td>
                        <td>
                            @if ($status->pct !== null && $status->band)
                                <x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill>
                            @elseif ($started($status))
                                <x-status-pill tone="info">Assessment started</x-status-pill>
                            @else
                                <x-status-pill>Not started</x-status-pill>
                            @endif
                        </td>
                        <td>
                            <span @class(['font-semibold text-critical-ink' => $when['late'], 'text-on-surface-variant' => ! $when['late']])>{{ $when['text'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-panel>
    @endif
</x-layouts.app>
