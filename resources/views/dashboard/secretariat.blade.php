@use('App\Oha\Profile')
@use('App\Support\Viz')
@use('App\Support\Bands')
{{--
    AAYMCA's dashboard (staff and Administrators, the same for all), in the site's newer
    style (design standard of 10 Nov 2026), built from the same pieces as a movement's own
    dashboard. Analytics only: six key figures; Stage 1 across the region; every movement's
    score, the bands and the zones; how the ODPs are carried out; ODP changes; what is due
    for re-assessment; people and money; and the detail by category, folded away.
--}}
@php
    $me = auth()->user();
    $rated = $insights->rated();
    $n = $rated->count();
    $overdueTasks = $odp['counts']['overdue'] ?? 0;
    $underWayCount = $pipeline['form'] + $pipeline['report'] + $pipeline['odp'] + $pipeline['sign'];
    $meanBand = $headline['mean'] !== null ? Bands::at((float) $headline['mean']) : null;
    $short = fn (string $name) => str_replace(' YMCA', '', $name);
    $label = 'text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant';
@endphp
<x-layouts.app title="Dashboard" :subtitle="'Welcome back, '.\Illuminate\Support\Str::before($me->name, ' ').' · the region at a glance. Scores come from approved OHA forms only.'">
    @include('demo.visitor-approvals')

    {{-- Analytics only: each person's own work is in My Work (its count on the menu badge), never here. --}}

    {{-- Key figures --}}
    <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-3">
        <x-kpi label="Movements rated" :value="$headline['assessed']" :unit="' / '.$headline['total']">
            <x-slot:visual><x-progress-ring :value="$headline['assessed']" :max="$headline['total']" :size="72" /></x-slot:visual>
            {{ $headline['total'] - $headline['assessed'] }} not yet rated
        </x-kpi>
        <x-kpi label="Mean health score" :value="$headline['mean'] !== null ? number_format((float) $headline['mean'], 1) : '—'" :unit="$headline['mean'] !== null ? '%' : null">
            @if ($meanBand)
                <x-slot:badge><x-status-pill :tone="Bands::tone($meanBand['code'])">{{ $meanBand['label'] }}</x-status-pill></x-slot:badge>
                <x-band-scale :pct="$headline['mean']" class="mt-1" />
                <span class="block mt-1">Across {{ $n }} rated {{ \Illuminate\Support\Str::plural('movement', $n) }}</span>
            @else
                No movement is rated yet
            @endif
        </x-kpi>
        <x-kpi label="At Risk or Critical" :value="$headline['at_risk']" :tone="$headline['at_risk'] > 0 ? 'critical' : null">
            Movements below 50%, calling for support
        </x-kpi>
        <x-kpi label="Assessments under way" :value="$underWayCount">
            At the form, report, ODP or signature
        </x-kpi>
        <x-kpi label="ODPs validated" :value="$headline['with_odp']">
            Signed by the Board Chairperson
        </x-kpi>
        <x-kpi label="ODP tasks overdue" :value="$overdueTasks" :tone="$overdueTasks > 0 ? 'critical' : null">
            Of {{ $odp['total'] }} tasks in {{ count($odp['rows']) }} {{ count($odp['rows']) === 1 ? 'ODP' : 'ODPs' }}
        </x-kpi>
    </section>

    <x-panel title="Stage 1 across the region" :subtitle="'Where each of the '.$pipeline['total'].' National Movements is: from not yet assessed to a signed ODP.'">
        <x-viz.pipeline :pipeline="$pipeline" />
    </x-panel>

    <div class="grid gap-md lg:grid-cols-[1.4fr_1fr] items-start">
        <x-panel title="Every movement’s health score" subtitle="Its overall score in the latest approved OHA form, highest first">
            <x-slot:actions><x-more-link :href="route('movements.index')">National Movements</x-more-link></x-slot:actions>
            @if ($n === 0)
                <x-empty-state icon="insights">No movement is rated yet. Each one appears here once its OHA form is approved.</x-empty-state>
            @else
                @php($unrated = $ranked->whereNull('latest_assessment_id'))
                <x-score-bars label-width="10rem" :rows="$ranked->whereNotNull('latest_assessment_id')->map(fn ($s) => [
                    'label' => $short($s->name), 'href' => route('movements.show', $s->movement), 'pct' => (float) $s->pct,
                    'band_code' => $s->band_code, 'band' => $s->band->label,
                ])->values()->all()">
                    @if ($unrated->isNotEmpty())
                        <span>Not yet rated ({{ $unrated->count() }}): {{ $unrated->map(fn ($s) => $short($s->name))->join(', ') }}</span>
                    @endif
                </x-score-bars>
            @endif
        </x-panel>

        <div class="flex flex-col gap-md">
            <x-panel title="Health bands" subtitle="How many movements are in each band">
                @php($bandMax = max(1, $bands->max('count')))
                <ul class="flex flex-col gap-[12px]">
                    @foreach ($bands as $row)
                        <li class="grid grid-cols-[9.5rem_minmax(0,1fr)_2rem] items-center gap-[12px] text-[0.875rem]">
                            @if ($row['underWay'])
                                <x-status-pill tone="info" class="justify-self-start">Assessment started</x-status-pill>
                            @else
                                <x-status-pill :tone="Bands::tone($row['band']->code)" class="justify-self-start">{{ $row['band']->label }}</x-status-pill>
                            @endif
                            <span class="h-2.5 rounded-full bg-[#f0eeea] overflow-hidden" role="img" aria-label="{{ $row['count'] }} {{ \Illuminate\Support\Str::plural('movement', $row['count']) }}">
                                <span class="block h-full rounded-full {{ $row['underWay'] ? 'bg-info-ink' : Bands::fill($row['band']->code) }}" style="width: {{ $row['count'] > 0 ? max(4, $row['count'] / $bandMax * 100) : 0 }}%"></span>
                            </span>
                            <span class="text-right font-bold tabular-nums">{{ $row['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-panel>

            <x-panel title="By zone" subtitle="Movements rated, their average score, and how many are At Risk or Critical">
                <div class="overflow-x-auto -mx-1">
                    <table class="w-full text-[0.875rem]">
                        <thead>
                            <tr class="{{ $label }} text-left">
                                <th class="font-semibold pb-sm px-1">Zone</th>
                                <th class="font-semibold pb-sm px-1 text-right">Rated</th>
                                <th class="font-semibold pb-sm px-1 w-2/5">Average</th>
                                <th class="font-semibold pb-sm px-1 text-right">At risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zones as $zone)
                                <tr class="border-t border-[#f0eeea]">
                                    <td class="py-[10px] px-1 font-medium">{{ $zone['zone'] }}</td>
                                    <td class="py-[10px] px-1 text-right tabular-nums text-on-surface-variant">{{ $zone['rated'] }} / {{ $zone['movements'] }}</td>
                                    <td class="py-[10px] px-1">
                                        @if ($zone['pct'] !== null)
                                            <span class="flex items-center gap-sm">
                                                <span class="flex-1 h-2 rounded-full bg-[#f0eeea] overflow-hidden"><span class="block h-full rounded-full {{ Bands::fill(Bands::at($zone['pct'])['code'] ?? null) }}" style="width: {{ $zone['pct'] }}%"></span></span>
                                                <span class="tabular-nums font-semibold w-12 text-right">{{ number_format($zone['pct'], 1) }}%</span>
                                            </span>
                                        @else
                                            <span class="text-on-surface-variant">None rated</span>
                                        @endif
                                    </td>
                                    <td @class(['py-[10px] px-1 text-right tabular-nums', 'font-bold text-critical-ink' => $zone['at_risk'] > 0])>{{ $zone['at_risk'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        </div>
    </div>

    <x-panel title="How the ODPs are being carried out" subtitle="The tasks in each movement’s newest ODP and their deadlines, as read from the ODP or set by its assessors" stack>
        <x-slot:actions><x-more-link :href="route('timelines')">Open Timelines</x-more-link></x-slot:actions>
        @if ($odp['total'] === 0)
            <x-empty-state icon="checklist">No ODP has tasks yet. They appear once an ODP is uploaded.</x-empty-state>
        @else
            <div class="flex flex-wrap gap-sm">
                <x-status-pill tone="good">Done {{ $odp['counts']['done'] ?? 0 }}</x-status-pill>
                <x-status-pill tone="critical">Overdue {{ $overdueTasks }}</x-status-pill>
                <x-status-pill tone="warning">Due within 30 days {{ $odp['counts']['soon'] ?? 0 }}</x-status-pill>
                <x-status-pill>Later {{ $odp['counts']['upcoming'] ?? 0 }}</x-status-pill>
                <x-status-pill tone="atrisk">No date {{ $odp['counts']['undated'] ?? 0 }}</x-status-pill>
            </div>
            {{-- Each movement side by side, falling behind first. A signed ODP opens its follow-up. --}}
            <div class="overflow-x-auto -mx-1">
                <table class="w-full min-w-[46rem] text-[0.875rem]">
                    <thead>
                        <tr class="{{ $label }} text-left">
                            <th class="font-semibold pb-sm px-1">Movement</th>
                            <th class="font-semibold pb-sm px-1 w-[30%]">Done</th>
                            <th class="font-semibold pb-sm px-1 text-right">Overdue</th>
                            <th class="font-semibold pb-sm px-1 text-right">To confirm</th>
                            <th class="font-semibold pb-sm px-1 text-right">Blocked</th>
                            <th class="font-semibold pb-sm px-1 pl-md">Next follow-up</th>
                            <th class="font-semibold pb-sm px-1"><span class="sr-only">Status</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($odp['rows'] as $row)
                            @php($m = $row['assessment']->movement)
                            @php($s = $row['summary'])
                            <tr class="border-t border-[#f0eeea]">
                                <td class="py-[11px] px-1">
                                    <a href="{{ $row['signed'] ? route('follow-up.show', $row['assessment']) : route('timelines', ['movement' => $m->slug]) }}" class="font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ $short($m->name) }}</a>
                                </td>
                                <td class="py-[11px] px-1">
                                    <span class="flex items-center gap-sm">
                                        <span class="flex-1 flex h-2 rounded-full overflow-hidden bg-[#dddad3]" role="img" aria-label="{{ $s['done'] }} of {{ $s['total'] }} done, {{ $s['overdue'] }} overdue">
                                            @if ($s['done'] > 0)<span class="bg-band-strong" style="width: {{ $s['done'] / max(1, $s['total']) * 100 }}%"></span>@endif
                                            @if ($s['overdue'] > 0)<span class="bg-band-critical {{ $s['done'] > 0 ? 'ml-0.5' : '' }}" style="width: {{ $s['overdue'] / max(1, $s['total']) * 100 }}%"></span>@endif
                                        </span>
                                        <span class="tabular-nums text-[0.8125rem] text-on-surface-variant whitespace-nowrap">{{ $s['done'] }} / {{ $s['total'] }} · {{ $s['pct'] }}%</span>
                                    </span>
                                </td>
                                <td @class(['py-[11px] px-1 text-right tabular-nums', 'font-bold text-critical-ink' => $s['overdue'] > 0])>{{ $s['overdue'] }}</td>
                                <td @class(['py-[11px] px-1 text-right tabular-nums', 'font-semibold text-info-ink' => $s['waiting'] > 0])>{{ $s['waiting'] }}</td>
                                <td @class(['py-[11px] px-1 text-right tabular-nums', 'font-semibold text-critical-ink' => $s['blocked'] > 0])>{{ $s['blocked'] }}</td>
                                <td class="py-[11px] px-1 pl-md text-[0.8125rem] whitespace-nowrap">
                                    @if ($row['meeting'])
                                        <span @class(['font-semibold text-critical-ink' => $row['meeting']['overdue'] && ! $row['paused']])>Follow-up {{ $row['meeting']['number'] }} · {{ $row['meeting']['due_on']->asDay() }}</span>
                                    @else
                                        <span class="text-on-surface-variant">{{ $row['signed'] ? 'All held' : 'Once signed' }}</span>
                                    @endif
                                </td>
                                <td class="py-[11px] px-1 text-right">
                                    @if ($row['paused'])
                                        <x-status-pill tone="warning">Paused</x-status-pill>
                                    @elseif ($row['behind'])
                                        <x-status-pill tone="critical">Falling behind</x-status-pill>
                                    @elseif ($row['signed'])
                                        <x-status-pill tone="good">On track</x-status-pill>
                                    @else
                                        <x-status-pill>Not yet signed</x-status-pill>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panel>

    @include('dashboard._odp-changes')

    <x-panel title="Re-assessment due" subtitle="Past their re-assessment date. The band sets the cadence: weaker movements are re-assessed sooner.">
        @if ($due->isEmpty())
            <x-empty-state icon="event_available">No movement is past its re-assessment date.</x-empty-state>
        @else
            <ul class="grid gap-x-xl sm:grid-cols-2">
                @foreach ($due as $status)
                    <li class="flex items-center gap-[12px] py-[11px] border-t border-[#f0eeea] text-[0.875rem]">
                        <a href="{{ route('movements.show', $status->movement) }}" class="flex-1 min-w-0 truncate font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ $status->name }}</a>
                        @if (isset($underWay[$status->movement_id]))
                            <x-status-pill tone="info">Assessment started</x-status-pill>
                        @elseif ($status->band)
                            <x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill>
                        @endif
                        <span class="text-right text-[0.8125rem] whitespace-nowrap">
                            <span class="font-semibold text-critical-ink">Was due {{ $status->next_assessment_on->asMonth() }}</span>
                            <span class="block text-on-surface-variant">{{ $status->next_assessment_on->diffForHumans(['parts' => 1]) }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    @if ($n > 0)
        @php($people = $insights->people())
        @php($finance = collect($insights->finance()))
        <div class="grid gap-md lg:grid-cols-2 items-start">
            <x-panel title="People across the region" subtitle="Added up across the movements that reported each figure" stack>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-sm gap-y-md">
                    @foreach ([['staff', 'Staff'], ['volunteers', 'Volunteers'], ['beneficiaries', 'People reached a year'], ['branches', 'Branches']] as [$key, $text])
                        <div>
                            <dt class="text-[0.78125rem] text-on-surface-variant">{{ $text }}</dt>
                            <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ Viz::number($people[$key]['total']) }}</dd>
                            <dd class="text-[0.75rem] text-on-surface-variant">{{ $people[$key]['reporting'] }} of {{ $n }} reporting</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($people['board']['total'] > 0)
                    <div class="flex flex-col gap-sm border-t border-[#f0eeea] pt-md">
                        <p class="text-[0.84375rem] font-semibold">Boards: {{ Viz::number($people['board']['total']) }} members across {{ $people['board']['reporting'] }} movements</p>
                        @foreach ([[$people['board_women']['total'], 'women'], [$people['board_under_30']['total'], 'under 30']] as [$count, $who])
                            @php($share = round($count * 100 / $people['board']['total']))
                            <div class="grid grid-cols-[5rem_1fr_3rem] items-center gap-sm text-[0.8125rem]">
                                <span class="text-on-surface-variant">{{ ucfirst($who) }}</span>
                                <span class="h-2 rounded-full bg-[#f0eeea] overflow-hidden"><span class="block h-full rounded-full bg-on-surface" style="width: {{ $share }}%"></span></span>
                                <span class="text-right font-semibold tabular-nums">{{ $share }}%</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-panel>
            <x-panel title="Reliance on international funding" subtitle="Share of income from outside the country (Q224). The form warns above 75%.">
                <x-viz.threshold-bars :rows="$finance->map(fn ($f) => ['label' => $short($f['movement']->name), 'value' => $f['international']])->sortByDesc('value')->values()->all()"
                                      :limit="75" limitLabel="75% — the form’s risk line" />
            </x-panel>
        </div>
        <x-panel title="Operating margin" subtitle="Surplus or deficit as a share of income, from each movement’s own figures (Q211–Q212). Currencies differ, so only the ratio is compared.">
            <x-viz.diverging :rows="$finance->map(fn ($f) => ['label' => $short($f['movement']->name), 'value' => $f['margin']])->sortByDesc('value')->values()->all()" />
        </x-panel>

        {{-- The detail by category and topic: there for whoever wants it, out of the way of the regional picture. --}}
        <details class="group bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)]">
            <summary class="oha-summary flex items-center gap-md p-lg cursor-pointer list-none">
                <span class="flex-1 min-w-0">
                    <span class="block text-[1.125rem] font-bold">Detail by category and topic</span>
                    <span class="block text-[0.875rem] text-on-surface-variant">Every category for every movement, where the region is weakest, funding and spending, policies, governance and Vision 2030.</span>
                </span>
                <span class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] transition-transform group-open:rotate-180" aria-hidden="true">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="px-lg pb-lg flex flex-col gap-lg">
                @php($sub = 'border-t border-[#f0eeea] pt-lg flex flex-col gap-md')
                <section class="{{ $sub }}">
                    <div><h3 class="text-[1rem] font-bold">Every movement, every category</h3><p class="text-[0.84375rem] text-on-surface-variant">Percentage scored in each category of the latest approved form. Darker is healthier.</p></div>
                    <x-viz.heatmap :heatmap="$insights->heatmap()" />
                </section>

                <section class="{{ $sub }}">
                    <div><h3 class="text-[1rem] font-bold">Where the region is weakest</h3><p class="text-[0.84375rem] text-on-surface-variant">Average score per category, weakest first.</p></div>
                    <x-score-bars :rows="collect($weakest)->map(fn ($row) => ['label' => $row['category']->name, 'pct' => (float) $row['pct'], 'band_code' => Bands::at((float) $row['pct'])['code'] ?? null, 'band' => Bands::at((float) $row['pct'])['label'] ?? '—'])->values()->all()" />
                </section>

                <div class="grid gap-lg lg:grid-cols-2 items-start border-t border-[#f0eeea] pt-lg">
                    @foreach ([['income_mix', Profile::INCOME_GROUPS, 'Where the money comes from', 'Average share of income by source (Q214–Q222).'], ['expense_mix', Profile::EXPENSE_GROUPS, 'Where the money goes', 'Average share of expenditure by type (Q229–Q237).']] as [$key, $groups, $title, $subtitle])
                        @php($avg = $insights->averageMix($key))
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">{{ $title }}</h3><p class="text-[0.84375rem] text-on-surface-variant">{{ $subtitle }}</p></div>
                            @if ($avg === null)
                                <x-empty-state icon="pie_chart">No rated movement gave these figures.</x-empty-state>
                            @else
                                <x-viz.stacked :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $avg['shares'][$k]])->values()->all()"
                                               :caption="'Regional average · '.$avg['movements'].' movements'" />
                                <div class="flex flex-col gap-sm border-t border-[#f0eeea] pt-sm">
                                    @foreach ($rated as $r)
                                        @continue(empty($r['profile'][$key]))
                                        <div class="grid grid-cols-[minmax(6rem,9rem)_1fr] items-center gap-sm">
                                            <span class="text-[0.8125rem] truncate">{{ $short($r['movement']->name) }}</span>
                                            <x-viz.stacked thin :legend="false" :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $r['profile'][$key][$k]])->values()->all()" />
                                        </div>
                                    @endforeach
                                </div>
                                <details class="text-[0.8125rem]">
                                    <summary class="cursor-pointer font-semibold w-fit">Show as a table</summary>
                                    <div class="relative overflow-x-auto mt-xs">
                                        <table class="w-full text-[0.8125rem]">
                                            <thead><tr class="text-left text-on-surface-variant"><th class="py-1 pr-sm">Movement</th>@foreach ($groups as $g)<th class="py-1 pr-sm">{{ $g['label'] }}</th>@endforeach</tr></thead>
                                            <tbody>
                                                @foreach ($rated as $r)
                                                    @continue(empty($r['profile'][$key]))
                                                    <tr class="border-t border-[#f0eeea]"><td class="py-1 pr-sm">{{ $r['movement']->name }}</td>@foreach (array_keys($groups) as $k)<td class="py-1 pr-sm tabular-nums">{{ Viz::number($r['profile'][$key][$k], 1) }}%</td>@endforeach</tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endif
                        </section>
                    @endforeach
                </div>

                <div class="grid gap-lg lg:grid-cols-2 items-start border-t border-[#f0eeea] pt-lg">
                    <section class="flex flex-col gap-md">
                        <div><h3 class="text-[1rem] font-bold">Policies in place</h3><p class="text-[0.84375rem] text-on-surface-variant">Share of rated movements with each policy (Q412–Q430). Weakest at the bottom.</p></div>
                        <x-viz.adoption :rows="$insights->adoption('policies', Profile::POLICIES)" />
                    </section>
                    <div class="flex flex-col gap-lg">
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">Governance practice</h3><p class="text-[0.84375rem] text-on-surface-variant">Share of rated movements following each practice.</p></div>
                            <x-viz.adoption :rows="$insights->adoption('governance', Profile::GOVERNANCE_PRACTICES)" labelWidth="14rem" />
                        </section>
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">Vision 2030 in the Strategic Plan</h3><p class="text-[0.84375rem] text-on-surface-variant">Of the movements whose plan reflects Vision 2030 (Q620), how many cover each component.</p></div>
                            <x-viz.adoption :rows="$insights->adoption('vision_2030', Profile::VISION_2030)" />
                        </section>
                    </div>
                </div>
            </div>
        </details>
    @endif
</x-layouts.app>
