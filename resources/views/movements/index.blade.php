<x-layouts.app :title="__('oha.nav.movements')" subtitle="All 23 National Movements. A movement is rated once one of its OHA forms is approved.">
    @php
        $stageLabels = ['not_assessed' => ['Not yet assessed', 'radio_button_unchecked', 'neutral'], 'form' => ['OHA form under way', 'table_view', 'info'],
            'report' => ['Report under way', 'description', 'info'], 'odp' => ['ODP under way', 'checklist', 'info'],
            'sign' => ['Awaiting signature', 'draw', 'warning'], 'complete' => ['Stage 1 complete', 'verified', 'good']];
        $short = ['financial' => 'Financial', 'governance' => 'Governance', 'constitution' => 'Constitution', 'me' => 'M&E', 'strategy' => 'Strategy',
            'diversity' => 'Diversity & youth', 'comms' => 'Comms', 'staff' => 'Staff & volunteers'];
        $query = fn (array $change) => route('movements.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
    @endphp

    <x-card>
        <x-viz.pipeline :pipeline="$pipeline" />
    </x-card>

    <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Filter movements">
        <label class="flex flex-col gap-xs text-[0.8125rem] grow sm:grow-0">
            <span class="font-semibold">Search</span>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, city or country"
                   class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem] sm:w-48">
        </label>
        @foreach ([['zone', 'Zone', $zones, 'code', 'name'], ['membership', 'Membership', $memberships, 'code', 'label'], ['band', 'Band', $bands, 'code', 'label']] as [$name, $label, $options, $value, $text])
            <label class="flex flex-col gap-xs text-[0.8125rem]">
                <span class="font-semibold">{{ $label }}</span>
                <select name="{{ $name }}" class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem]">
                    <option value="">All</option>
                    @foreach ($options as $option)
                        <option value="{{ $option->{$value} }}" @selected(($filters[$name] ?? null) === $option->{$value})>{{ $option->{$text} }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <label class="flex flex-col gap-xs text-[0.8125rem]">
            <span class="font-semibold">Stage</span>
            <select name="stage" class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem]">
                <option value="">All</option>
                @foreach ($stageLabels as $key => [$label])
                    <option value="{{ $key }}" @selected(($filters['stage'] ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <input type="hidden" name="view" value="{{ $view }}">
        <x-button variant="secondary">Filter</x-button>
        @if (array_filter(\Illuminate\Support\Arr::except($filters, 'view')))
            <a href="{{ route('movements.index', ['view' => $view]) }}" class="text-[0.875rem] text-primary underline pb-2">Clear</a>
        @endif
        <nav class="ml-auto flex rounded border-[1.5px] border-outline-variant overflow-hidden text-[0.8125rem]" aria-label="View">
            @foreach (['cards' => ['Cards', 'grid_view'], 'table' => ['Table', 'table_rows']] as $key => [$label, $icon])
                <a href="{{ $query(['view' => $key]) }}" @if ($view === $key) aria-current="page" @endif
                   @class(['px-sm py-1.5 flex items-center gap-xs', 'bg-primary text-on-primary' => $view === $key, 'hover:bg-surface-container' => $view !== $key])>
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>{{ $label }}
                </a>
            @endforeach
        </nav>
    </form>

    @if ($statuses->isEmpty())
        <x-empty-state icon="filter_alt_off">No movement matches these filters.</x-empty-state>
    @elseif ($view === 'cards')
        @foreach ($statuses->groupBy(fn ($s) => $s->movement->zone->name) as $zone => $group)
            <section class="flex flex-col gap-sm" aria-labelledby="zone-{{ \Illuminate\Support\Str::slug($zone) }}">
                <h2 id="zone-{{ \Illuminate\Support\Str::slug($zone) }}" class="text-[1rem] font-bold text-primary flex items-baseline gap-sm">
                    {{ $zone }} <span class="text-[0.8125rem] font-normal text-on-surface-variant">{{ $group->count() }} {{ $group->count() === 1 ? 'movement' : 'movements' }} · {{ $group->whereNotNull('pct')->count() }} rated</span>
                </h2>
                <ul class="grid sm:grid-cols-2 xl:grid-cols-3 gap-md">
                    @foreach ($group as $status)
                        @php
                            $m = $status->movement;
                            [$stageLabel, $stageIcon, $stageTone] = $stageLabels[$stage($status)];
                            $who = $assessors[$m->id] ?? collect();
                            $mine = $cells[$m->id] ?? null;
                        @endphp
                        <li class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-sm hover:border-primary">
                            <div class="flex items-start gap-sm">
                                <div class="flex-1 min-w-0">
                                    <a href="{{ route('movements.show', $m) }}" class="text-[1rem] font-bold text-primary underline decoration-1 underline-offset-2">{{ $m->name }}</a>
                                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $m->city }} · {{ $m->country }}</p>
                                    <div class="flex flex-wrap gap-xs mt-xs">
                                        <x-chip :icon="$m->membershipStatus->icon">{{ $m->membershipStatus->label }}</x-chip>
                                        <x-band-chip :band="$status->band" />
                                    </div>
                                </div>
                                <x-viz.ring :pct="$status->pct" :band="$status->band" :size="60" />
                            </div>

                            @if ($mine)
                                <div class="grid grid-cols-8 gap-[2px]" role="img" aria-label="Category scores: {{ $categories->map(fn ($c) => ($short[$c->code] ?? $c->name).' '.(isset($mine[$c->code]) ? round($mine[$c->code]).'%' : 'not rated'))->join(', ') }}">
                                    @foreach ($categories as $c)
                                        @php($h = \App\Support\Viz::heat($mine[$c->code] ?? null))
                                        <span class="h-3 rounded-sm {{ $h['fill'] }}" title="{{ $c->name }}: {{ isset($mine[$c->code]) ? round($mine[$c->code]).'% · '.$h['band'] : 'not rated' }}"></span>
                                    @endforeach
                                </div>
                                <p class="text-[0.8125rem] text-on-surface-variant -mt-xs">{{ rtrim(rtrim(number_format((float) $status->points_achieved, 2), '0'), '.') }} of {{ $status->points_available }} points · assessed {{ $status->last_assessed_on?->format('M Y') }}</p>
                            @else
                                <p class="text-[0.8125rem] text-on-surface-variant">No approved OHA form yet{{ $m->planned_assessment_label ? ' · planned '.$m->planned_assessment_label : '' }}.</p>
                            @endif

                            <div class="flex flex-wrap items-center gap-sm border-t border-surface-container pt-sm text-[0.8125rem]">
                                <x-chip :tone="$stageTone" :icon="$stageIcon">{{ $stageLabel }}</x-chip>
                                @if (auth()->user()->isSecretariat())
                                    @if ($who->isEmpty())
                                        <span class="flex items-center gap-xs {{ auth()->user()->isAdmin() ? 'text-serious-ink font-semibold' : 'text-on-surface-variant' }}">
                                            <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">person_off</span>No assessor
                                        </span>
                                    @else
                                        <span class="flex items-center gap-xs text-on-surface-variant truncate" title="Assessed by {{ $who->pluck('name')->join(', ') }}">
                                            <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">person</span>{{ $who->pluck('name')->join(', ') }}
                                        </span>
                                    @endif
                                @endif
                                <span class="ml-auto {{ $status->reassessment_overdue ? 'font-semibold text-critical-ink' : 'text-on-surface-variant' }}">
                                    {{ $status->next_assessment_on ? 'Next '.$status->next_assessment_on->format('M Y').($status->reassessment_overdue ? ' · overdue' : '') : 'Not scheduled' }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @else
        <div class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg overflow-x-auto">
            <table class="w-full text-[0.875rem]">
                <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                    <tr class="border-b-[1.5px] border-outline-variant">
                        <th class="px-md py-sm">Movement</th>
                        <th class="px-md py-sm">Zone</th>
                        <th class="px-md py-sm">Membership</th>
                        <th class="px-md py-sm">Stage</th>
                        <th class="px-md py-sm w-56">Score</th>
                        <th class="px-md py-sm">Band</th>
                        <th class="px-md py-sm">Next assessment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($statuses as $status)
                        @php([$stageLabel, $stageIcon, $stageTone] = $stageLabels[$stage($status)])
                        <tr class="border-b border-surface-container last:border-0">
                            <td class="px-md py-sm"><a href="{{ route('movements.show', $status->movement) }}" class="font-semibold text-primary underline">{{ $status->name }}</a>
                                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $status->movement->city }}</span></td>
                            <td class="px-md py-sm">{{ $status->movement->zone->name }}</td>
                            <td class="px-md py-sm">{{ $status->movement->membershipStatus->label }}</td>
                            <td class="px-md py-sm"><x-chip :tone="$stageTone" :icon="$stageIcon">{{ $stageLabel }}</x-chip></td>
                            <td class="px-md py-sm">
                                @if ($status->pct !== null)
                                    <x-bar :value="(float) $status->pct" :label="rtrim(rtrim(number_format((float) $status->points_achieved, 2), '0'), '.').'/'.$status->points_available.' · '.number_format((float) $status->pct, 1).'%'"
                                           :tone="['excellent' => 'good', 'strong' => 'good', 'developing' => 'warning', 'atrisk' => 'serious', 'critical' => 'critical'][$status->band_code] ?? 'neutral'" wide />
                                @else
                                    <span class="text-on-surface-variant">Not rated</span>
                                @endif
                            </td>
                            <td class="px-md py-sm"><x-band-chip :band="$status->band" /></td>
                            <td class="px-md py-sm">
                                @if ($status->next_assessment_on)
                                    <span class="{{ $status->reassessment_overdue ? 'font-semibold text-critical-ink' : '' }}">{{ $status->next_assessment_on->format('M Y') }}{{ $status->reassessment_overdue ? ' · overdue' : '' }}</span>
                                @else
                                    <span class="text-on-surface-variant">Not scheduled</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
