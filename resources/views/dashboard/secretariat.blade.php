@use('App\Oha\Profile')
@use('App\Support\Viz')
<x-layouts.app title="Dashboard" subtitle="Organizational health across the alliance, from approved OHA forms only.">
    @php
        $rated = $insights->rated();
        $n = $rated->count();
    @endphp

    {{-- Headline figures --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-md">
        <x-stat-tile label="Movements rated" :value="$headline['assessed']" :unit="' / '.$headline['total']">
            <x-slot:sub>{{ $headline['total'] - $headline['assessed'] }} not yet rated</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Mean health score" :value="$headline['mean'] ?? '—'" :unit="$headline['mean'] !== null ? '%' : null">
            <x-slot:sub>{{ $n ? 'Across '.$n.' rated '.($n === 1 ? 'movement' : 'movements') : 'No movement is rated yet' }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="At Risk or Critical" :value="$headline['at_risk']">
            <x-slot:sub>Below 50%</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Assessments under way" :value="$pipeline['form'] + $pipeline['report'] + $pipeline['odp'] + $pipeline['sign']">
            <x-slot:sub>Form, report, ODP or signature</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="ODPs validated" :value="$headline['with_odp']" class="col-span-2 lg:col-span-1">
            <x-slot:sub>Signed by the Board Chairperson</x-slot:sub>
        </x-stat-tile>
    </div>

    <x-card title="Stage 1 across the alliance" subtitle="Where each of the {{ $pipeline['total'] }} National Movements is: from not yet assessed to a signed ODP.">
        <x-viz.pipeline :pipeline="$pipeline" />
    </x-card>

    @include('dashboard._odp-changes')

    @if ($n === 0)
        <x-card title="Analytics from the OHA forms">
            <x-empty-state icon="insights">
                No movement has an approved OHA form yet. As each form is approved, this page fills in: a heatmap of every category,
                averages by zone, funding and spending mixes, reliance on international money, operating margins, staff, volunteers
                and people reached, board make-up, policy adoption and Vision 2030 coverage.
            </x-empty-state>
        </x-card>
    @else
        <x-card title="Every movement, every category" subtitle="Percentage scored in each category of the latest approved form. Darker is healthier.">
            <x-viz.heatmap :heatmap="$insights->heatmap()" />
        </x-card>

        <div class="grid lg:grid-cols-2 gap-md items-start">
            <x-card title="Health bands" subtitle="How many movements are in each band.">
                @php($bandTotal = max(1, $bands->sum('count')))
                <ul class="flex flex-col gap-sm">
                    @foreach ($bands as $row)
                        {{-- Side by side from 22rem; on the narrowest phones the band sits above its bar. --}}
                        <li class="grid min-[22rem]:grid-cols-[8rem_minmax(0,1fr)] items-center gap-x-sm gap-y-xs">
                            <x-band-chip :band="$row['band']" />
                            <x-bar wide :value="$row['count']" :max="$bandTotal" :label="$row['count'].' '.($row['count'] === 1 ? 'movement' : 'movements')"
                                   :tone="['excellent' => 'good', 'strong' => 'good', 'developing' => 'warning', 'atrisk' => 'serious', 'critical' => 'critical'][$row['band']->code] ?? 'neutral'" />
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <x-card title="Where the alliance is weakest" subtitle="Average score per category, weakest first.">
                @foreach ($weakest as $row)
                    <div class="grid sm:grid-cols-[12rem_1fr] items-center gap-x-sm gap-y-xs text-[0.875rem]">
                        <span>{{ $row['category']->name }}</span>
                        <x-bar :value="$row['pct']" :label="number_format($row['pct'], 1).'%'" />
                    </div>
                @endforeach
            </x-card>

            <x-card title="By zone" subtitle="Average overall score of the rated movements in each zone.">
                @foreach ($insights->zones() as $zone)
                    <div class="grid sm:grid-cols-[12rem_1fr] items-center gap-x-sm gap-y-xs text-[0.875rem]">
                        <span>{{ $zone['zone'] }} <span class="text-[0.8125rem] text-on-surface-variant">· {{ $zone['movements'] }} rated</span></span>
                        <x-bar :value="$zone['pct']" :label="number_format($zone['pct'], 1).'%'" />
                    </div>
                @endforeach
            </x-card>

            @php($people = $insights->people())
            <x-card title="People" subtitle="Added up across the movements that reported each figure.">
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-md">
                    @foreach ([['staff', 'Staff'], ['volunteers', 'Volunteers'], ['beneficiaries', 'People reached a year'], ['branches', 'Branches']] as [$key, $label])
                        <div>
                            <dt class="text-[0.8125rem] text-on-surface-variant">{{ $label }}</dt>
                            <dd class="text-[1.5rem] font-bold text-primary leading-tight">{{ Viz::number($people[$key]['total']) }}</dd>
                            <dd class="text-[0.8125rem] text-on-surface-variant">{{ $people[$key]['reporting'] }} of {{ $n }} reporting</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($people['board']['total'] > 0)
                    <div class="flex flex-col gap-xs">
                        <p class="text-[0.8125rem] font-semibold">Boards: {{ Viz::number($people['board']['total']) }} members across {{ $people['board']['reporting'] }} movements</p>
                        <x-bar :value="$people['board_women']['total']" :max="$people['board']['total']" :label="round($people['board_women']['total'] * 100 / $people['board']['total']).'% women'" wide />
                        <x-bar :value="$people['board_under_30']['total']" :max="$people['board']['total']" :label="round($people['board_under_30']['total'] * 100 / $people['board']['total']).'% under 30'" wide />
                    </div>
                @endif
            </x-card>
        </div>

        <div class="grid lg:grid-cols-2 gap-md items-start">
            @foreach ([['income_mix', Profile::INCOME_GROUPS, 'Where the money comes from', 'Average share of income by source (Q214–Q222).'], ['expense_mix', Profile::EXPENSE_GROUPS, 'Where the money goes', 'Average share of expenditure by type (Q229–Q237).']] as [$key, $groups, $title, $subtitle])
                @php($avg = $insights->averageMix($key))
                <x-card :title="$title" :subtitle="$subtitle">
                    @if ($avg === null)
                        <x-empty-state icon="pie_chart">No rated movement gave these figures.</x-empty-state>
                    @else
                        <x-viz.stacked :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $avg['shares'][$k]])->values()->all()"
                                       :caption="'Alliance average · '.$avg['movements'].' movements'" />
                        <div class="flex flex-col gap-sm border-t border-surface-container pt-sm">
                            @foreach ($rated as $r)
                                @continue(empty($r['profile'][$key]))
                                <div class="grid grid-cols-[minmax(6rem,9rem)_1fr] items-center gap-sm">
                                    <span class="text-[0.8125rem] truncate">{{ str_replace(' YMCA', '', $r['movement']->name) }}</span>
                                    <x-viz.stacked thin :legend="false" :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $r['profile'][$key][$k]])->values()->all()" />
                                </div>
                            @endforeach
                        </div>
                        <details class="text-[0.8125rem]">
                            <summary class="cursor-pointer text-primary underline">Show as a table</summary>
                            <div class="overflow-x-auto mt-xs">
                                <table class="w-full text-[0.8125rem]">
                                    <thead><tr class="text-left text-on-surface-variant"><th class="py-1 pr-sm">Movement</th>@foreach ($groups as $g)<th class="py-1 pr-sm">{{ $g['label'] }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($rated as $r)
                                            @continue(empty($r['profile'][$key]))
                                            <tr class="border-t border-surface-container"><td class="py-1 pr-sm">{{ $r['movement']->name }}</td>@foreach (array_keys($groups) as $k)<td class="py-1 pr-sm tabular-nums">{{ Viz::number($r['profile'][$key][$k], 1) }}%</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </x-card>
            @endforeach
        </div>

        @php($finance = collect($insights->finance()))
        <div class="grid lg:grid-cols-2 gap-md items-start">
            <x-card title="Reliance on international funding" subtitle="Share of income from outside the country (Q224). The form warns above 75%.">
                <x-viz.threshold-bars :rows="$finance->map(fn ($f) => ['label' => str_replace(' YMCA', '', $f['movement']->name), 'value' => $f['international']])->sortByDesc('value')->values()->all()"
                                      :limit="75" limitLabel="75% — the form’s risk line" />
            </x-card>
            <x-card title="Operating margin" subtitle="Surplus or deficit as a share of income, from each movement’s own figures (Q211–Q212). Currencies differ, so only the ratio is compared.">
                <x-viz.diverging :rows="$finance->map(fn ($f) => ['label' => str_replace(' YMCA', '', $f['movement']->name), 'value' => $f['margin']])->sortByDesc('value')->values()->all()" />
            </x-card>
        </div>

        <div class="grid lg:grid-cols-2 gap-md items-start">
            <x-card title="Policies in place" subtitle="Share of rated movements with each policy (Q412–Q430). Weakest at the bottom.">
                <x-viz.adoption :rows="$insights->adoption('policies', Profile::POLICIES)" />
            </x-card>
            <div class="flex flex-col gap-md">
                <x-card title="Governance practice" subtitle="Share of rated movements following each practice.">
                    <x-viz.adoption :rows="$insights->adoption('governance', Profile::GOVERNANCE_PRACTICES)" labelWidth="14rem" />
                </x-card>
                <x-card title="Vision 2030 in the Strategic Plan" subtitle="Of the movements whose plan reflects Vision 2030 (Q620), how many cover each component.">
                    <x-viz.adoption :rows="$insights->adoption('vision_2030', Profile::VISION_2030)" />
                </x-card>
            </div>
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-md items-start">
        <x-card title="Outstanding actions" subtitle="What is waiting on you.">
            <x-slot:actions><a href="{{ route('my-work') }}" class="text-[0.875rem] text-primary underline">Open My Work</a></x-slot:actions>
            @if ($work->isEmpty())
                <x-empty-state icon="task_alt">Nothing is waiting on you.</x-empty-state>
            @else
                <ul class="flex flex-col gap-sm">
                    @foreach ($work as $entry)
                        @include('partials.work-row', ['entry' => $entry])
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Re-assessment due" subtitle="Cadence is set by the health band: weaker movements are re-assessed sooner.">
            @if ($due->isEmpty())
                <x-empty-state icon="event_available">No movement is past its re-assessment date.</x-empty-state>
            @else
                <div class="overflow-x-auto">
                <table class="w-full text-[0.875rem]">
                    <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                        <tr><th class="py-xs">Movement</th><th class="py-xs">Band</th><th class="py-xs">Was due</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($due as $status)
                            <tr class="border-t border-surface-container">
                                <td class="py-xs"><a href="{{ route('movements.show', $status->movement) }}" class="text-primary underline">{{ $status->name }}</a></td>
                                <td class="py-xs"><x-band-chip :band="$status->band" /></td>
                                <td class="py-xs">{{ $status->next_assessment_on->format('M Y') }} <span class="text-on-surface-variant">({{ $status->next_assessment_on->diffForHumans(['parts' => 1]) }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-card>
    </div>
</x-layouts.app>
