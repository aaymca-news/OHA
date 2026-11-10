<x-layouts.app :title="__('oha.nav.movements')" :subtitle="'All '.$pipeline['total'].' National Movements. A movement is rated once one of its OHA forms is approved.'">
    @php
        // $stage($status) gives an App\Enums\Stage: its label, icon and tone.
        $started = fn ($status) => ! in_array($stage($status), [\App\Enums\Stage::NotAssessed, \App\Enums\Stage::Complete], true);
        $short = ['financial' => 'Financial', 'governance' => 'Governance', 'constitution' => 'Constitution', 'me' => 'M&E', 'strategy' => 'Strategy',
            'diversity' => 'Diversity & youth', 'comms' => 'Comms', 'staff' => 'Staff & volunteers'];
        $query = fn (array $change) => route('movements.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
    @endphp

    <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
        <span class="font-semibold">Scores for reporting (CSV):</span>
        <x-button variant="link" icon="download" :href="route('downloads.scores')">Latest, per movement</x-button>
        <x-button variant="link" icon="download" :href="route('downloads.scores', ['all' => 1])">Every approved assessment</x-button>
        @can('create', \App\Models\Movement::class)
            <x-button :href="route('admin.movements.create')" size="sm" icon="add" class="ml-auto">Add a National Movement</x-button>
        @endcan
    </div>

    <x-card>
        <x-viz.pipeline :pipeline="$pipeline" />
    </x-card>

    <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Filter movements">
        <x-input type="search" name="q" label="Search" size="sm" :value="$filters['q'] ?? ''" placeholder="Name, city or country"
                 wrap="grow sm:grow-0" class="sm:w-48" />
        @foreach ([['zone', 'Zone', $zones, 'code', 'name'], ['membership', 'Membership', $memberships, 'code', 'label'], ['band', 'Band', $bands, 'code', 'label']] as [$name, $label, $options, $value, $text])
            <x-select :name="$name" :label="$label" size="sm" placeholder="All" :value="$filters[$name] ?? ''"
                      :options="collect($options)->mapWithKeys(fn ($option) => [$option->{$value} => $option->{$text}])->all()" />
        @endforeach
        <x-select name="stage" label="Stage" size="sm" placeholder="All" :value="$filters['stage'] ?? ''"
                  :options="collect(\App\Enums\Stage::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
        <input type="hidden" name="view" value="{{ $view }}">
        <x-button variant="secondary">Filter</x-button>
        @if (array_filter(\Illuminate\Support\Arr::except($filters, 'view')))
            <a href="{{ route('movements.index', ['view' => $view]) }}" class="text-[0.875rem] text-primary underline pb-2">Clear</a>
        @endif
        <x-segmented label="View" variant="joined" class="ml-auto">
            @foreach (['cards' => ['Cards', 'grid_view'], 'table' => ['Table', 'table_rows']] as $key => [$label, $icon])
                <x-segmented.link :href="$query(['view' => $key])" :active="$view === $key" :icon="$icon">{{ $label }}</x-segmented.link>
            @endforeach
        </x-segmented>
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
                            $at = $stage($status);
                            [$stageLabel, $stageIcon, $stageTone] = [$at->label(), $at->icon(), $at->tone()];
                            $who = $assessors[$m->id] ?? collect();
                            $mine = $cells[$m->id] ?? null;
                        @endphp
                        <li class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-sm transition duration-200 hover:border-secondary hover:shadow-[0_0_0_4px_var(--color-brand-red-wash),0_6px_18px_rgba(200,16,46,0.12)]">
                            <div class="flex items-start gap-sm">
                                <div class="flex-1 min-w-0">
                                    <a href="{{ route('movements.show', $m) }}" class="text-[1rem] font-bold text-primary underline decoration-1 underline-offset-2">{{ $m->name }}</a>
                                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $m->city }} · {{ $m->country }}</p>
                                    <div class="flex flex-wrap gap-xs mt-xs">
                                        <x-chip :icon="$m->membershipStatus->icon">{{ $m->membershipStatus->label }}</x-chip>
                                        <x-band-chip :band="$status->band" :under-way="$started($status)" />
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
                                <p class="text-[0.8125rem] text-on-surface-variant -mt-xs">{{ \App\Support\Viz::figure((float) $status->points_achieved) }} of {{ $status->points_available }} points · assessed {{ $status->last_assessed_on?->asMonth() }}</p>
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
                                        <span class="flex items-center gap-xs text-on-surface-variant min-w-0" title="Assessed by {{ $who->pluck('name')->join(', ') }}">
                                            <span class="flex -space-x-1.5 shrink-0">
                                                @foreach ($who->take(3) as $person)
                                                    <x-avatar :user="$person" size="sm" class="ring-2 ring-surface-container-lowest" />
                                                @endforeach
                                            </span>
                                            <span class="truncate">{{ $who->pluck('name')->join(', ') }}</span>
                                        </span>
                                    @endif
                                @endif
                                <span class="ml-auto {{ $status->reassessment_overdue && ! $status->movement->followUpPaused() ? 'font-semibold text-critical-ink' : 'text-on-surface-variant' }}">
                                    {{ $status->next_assessment_on ? 'Next '.$status->next_assessment_on->asMonth().($status->movement->followUpPaused() ? ' · paused' : ($status->reassessment_overdue ? ' · overdue' : '')) : 'Not scheduled' }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @else
        <x-table>
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
                @php([$stageLabel, $stageIcon, $stageTone] = [$stage($status)->label(), $stage($status)->icon(), $stage($status)->tone()])
                <tr class="border-b border-surface-container last:border-0">
                    <td><a href="{{ route('movements.show', $status->movement) }}" class="font-semibold text-primary underline">{{ $status->name }}</a>
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $status->movement->city }}</span></td>
                    <td>{{ $status->movement->zone->name }}</td>
                    <td>{{ $status->movement->membershipStatus->label }}</td>
                    <td><x-chip :tone="$stageTone" :icon="$stageIcon">{{ $stageLabel }}</x-chip></td>
                    <td>
                        @if ($status->pct !== null)
                            <x-bar :value="(float) $status->pct" :label="\App\Support\Viz::points($status->points_achieved, $status->points_available, '/').' · '.\App\Support\Viz::pct($status->pct)"
                                   :tone="\App\Enums\BandCode::toneOf($status->band_code)" wide />
                        @else
                            <span class="text-on-surface-variant">Not rated</span>
                        @endif
                    </td>
                    <td><x-band-chip :band="$status->band" :under-way="$started($status)" /></td>
                    <td>
                        @if ($status->next_assessment_on)
                            <span class="{{ $status->reassessment_overdue && ! $status->movement->followUpPaused() ? 'font-semibold text-critical-ink' : '' }}">{{ $status->next_assessment_on->asMonth() }}{{ $status->movement->followUpPaused() ? ' · paused' : ($status->reassessment_overdue ? ' · overdue' : '') }}</span>
                        @else
                            <span class="text-on-surface-variant">Not scheduled</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-layouts.app>
