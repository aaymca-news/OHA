@use('App\Oha\Profile')
@use('App\Support\Viz')
@use('App\Support\Bands')
{{--
    AAYMCA's dashboard (staff, Administrators and Super Administrators alike), analytics only
    (decided 15 Nov 2026): nothing about work, tasks or what is due, which live in My Work,
    Timelines and the follow-ups. For the region or one zone (as approved on the mockup of
    23 Nov 2026): the key figures; who needs support; from baseline to now and the band
    movement; the region's health shape and every movement's; every area for every movement;
    red flags and the points most often missed; money; the zones; the least adopted policies,
    people, and what finished cycles delivered. The full detail (funding mixes, every policy
    and practice, Vision 2030) and how each figure is worked out are folded away at the end.
--}}
@php
    $f = $data['figures'];
    $meanBand = $f['mean'] !== null ? Bands::at((float) $f['mean']) : null;
    $short = fn (string $name) => str_replace(' YMCA', '', $name);
    $th = 'text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant pb-sm px-1';
    $link = 'font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface';
    $people = $data['people'];
    $boardShare = fn (string $key) => $people['board']['total'] > 0 ? (int) round($people[$key]['total'] * 100 / $people['board']['total']) : null;
@endphp
<x-layouts.app title="Dashboard" :subtitle="($zone ? $zone->name : 'The region').' · scores from approved OHA forms and follow-ups'">
    @if ($f['assessed'] > 0)
        <x-slot:actions>
            <x-download-menu :pdf="route('dashboard.report', array_filter(['format' => 'pdf', 'zone' => $zone?->id]))" :xlsx="route('dashboard.report', array_filter(['format' => 'xlsx', 'zone' => $zone?->id]))" />
        </x-slot:actions>
    @endif
    <x-segmented label="Zone">
        <x-segmented.link :href="route('dashboard')" :active="$zone === null">All zones</x-segmented.link>
        @foreach ($zones as $z)
            <x-segmented.link :href="route('dashboard', ['zone' => $z->id])" :active="$zone?->id === $z->id">{{ $z->name }}</x-segmented.link>
        @endforeach
    </x-segmented>

    {{-- Key figures --}}
    {{-- As many tiles a row as fit (at least 12.5rem each); a short last row widens to fill it, so no gap is left. --}}
    <section aria-label="Key figures" class="flex flex-wrap gap-md *:flex-[1_1_12.5rem]">
        <x-kpi label="Movements assessed" info="movements_assessed" :value="$f['assessed']" :unit="' / '.$f['total']">
            <x-slot:visual><x-progress-ring :value="$f['assessed']" :max="max(1, $f['total'])" :size="64" /></x-slot:visual>
            {{ $f['total'] - $f['assessed'] }} not assessed yet
        </x-kpi>
        <x-kpi label="Average current score" info="average_score" :value="$f['mean'] !== null ? number_format((float) $f['mean'], 1) : '—'" :unit="$f['mean'] !== null ? '%' : null">
            @if ($meanBand)
                <x-slot:badge><x-status-pill :tone="Bands::tone($meanBand['code'])">{{ $meanBand['label'] }}</x-status-pill></x-slot:badge>
            @endif
            @if ($f['mean_change'] !== null)
                <span class="font-semibold text-good-ink">{{ Viz::signed($f['mean_change']) }}</span> points since baseline, on average
            @else
                No follow-up score yet
            @endif
        </x-kpi>
        <x-kpi label="Improving" info="improving" :value="$f['improved']" :unit="' of '.$f['followed']">
            followed up · {{ $f['band_up'] }} moved up a band
        </x-kpi>
        <x-kpi label="At Risk or Critical" info="at_risk" :value="$f['at_risk']" :tone="$f['at_risk'] > 0 ? 'critical' : null">
            {{ $f['at_risk_baseline'] }} at baseline
        </x-kpi>
        <x-kpi label="Backed by evidence" info="evidence_backed" :value="$f['evidence'] !== null ? $f['evidence'] : '—'" :unit="$f['evidence'] !== null ? '%' : null">
            {{ $f['evidence_yes'] > 0 ? 'of '.$f['evidence_yes'].' “Yes” answers evidence can prove' : 'How far the scores are proven' }}
        </x-kpi>
    </section>

    @if ($f['assessed'] === 0)
        <x-panel>
            <x-empty-state icon="insights">No movement {{ $zone ? 'in '.$zone->name.' ' : '' }}is assessed yet. Its figures appear here once its first OHA form is approved.</x-empty-state>
        </x-panel>
    @else
        <x-panel title="Who needs support" info="support_quadrants" subtitle="Where each movement is (its current score) and where it is going (points since baseline). Hover or tap a movement.">
            <x-viz.support-matrix :matrix="$data['matrix']" />
        </x-panel>

        <div class="grid gap-md lg:grid-cols-2 items-start">
            <x-panel title="From baseline to now" info="change_since_baseline" subtitle="Each movement followed up: its baseline (hollow) and its current score (filled), biggest gain first">
                <x-slot:actions><x-more-link :href="route('journeys.index')">Every journey</x-more-link></x-slot:actions>
                @if ($data['gains'] === [])
                    <x-empty-state icon="show_chart">No movement has a follow-up score yet: each closed follow-up adds one.</x-empty-state>
                @else
                    @php($lowest = collect($data['gains'])->flatMap(fn ($m) => [$m['journey']['first'], $m['journey']['now']])->min())
                    <x-viz.dumbbell :from="(int) min(40, floor($lowest / 10) * 10)" :rows="collect($data['gains'])->map(fn ($m) => ['movement' => $m['movement'], 'from' => $m['journey']['first'], 'to' => $m['journey']['now'], 'change' => $m['change']])->all()" />
                @endif
            </x-panel>

            <x-panel title="Band movement" info="health_band" subtitle="Movements with a baseline: their band then and now">
                @if (collect($data['bands'])->sum('baseline') === 0)
                    <x-empty-state icon="swap_vert">No assessment is closed yet, so no movement has a baseline.</x-empty-state>
                @else
                    <x-viz.band-flow :bands="$data['bands']" :flows="$data['flows']" />
                @endif
            </x-panel>
        </div>

        @include('dashboard._region-shape')

        <x-panel title="Every movement’s shape" info="area_score" subtitle="One octagon per movement, best score first: similar shapes share the same weak spots">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-sm">
                @foreach ($data['heatmap'] as $row)
                    @php($rowBand = Bands::at($row['pct']))
                    @php($values = array_map(fn ($a) => $row['cells'][$a['code']] ?? null, $data['shape']['areas']))
                    @php($corners = count($values))
                    <a href="{{ route('movements.show', $row['movement']) }}" class="flex flex-col items-center gap-[2px] rounded-xl border border-[#f0eeea] px-sm py-sm text-on-surface no-underline hover:border-on-surface"
                       aria-label="{{ $row['movement']->name }}, {{ Viz::pct($row['pct']) }}{{ $rowBand ? ', '.$rowBand['label'] : '' }}">
                        <svg viewBox="0 0 140 132" class="w-[7.5rem] h-auto" aria-hidden="true">
                            <polygon points="{{ \App\Support\Radar::ring($corners, 100, 56, 70, 66) }}" fill="#faf9f6" stroke="#e4e1db" />
                            <polygon points="{{ \App\Support\Radar::ring($corners, 70, 56, 70, 66) }}" fill="none" stroke="#ebe8e2" />
                            <polygon points="{{ \App\Support\Radar::ring($corners, 50, 56, 70, 66) }}" fill="none" stroke="#ebe8e2" />
                            <polygon points="{{ \App\Support\Radar::polygon($values, 56, 70, 66) }}" fill="{{ Viz::bandStroke($rowBand['code'] ?? null) }}" fill-opacity="0.3" stroke="{{ Viz::bandStroke($rowBand['code'] ?? null) }}" stroke-width="1.8" stroke-linejoin="round" />
                        </svg>
                        <span class="text-[0.8125rem] font-bold text-center">{{ $short($row['movement']->name) }}</span>
                        <span class="text-[0.78125rem] text-on-surface-variant tabular-nums">{{ Viz::pct($row['pct']) }}{{ $rowBand ? ' · '.$rowBand['label'] : '' }}</span>
                    </a>
                @endforeach
            </div>
        </x-panel>

        <x-panel title="Every movement, every area" info="area_score" subtitle="Hover an area for its full name">
            <x-viz.heatmap :categories="$data['categories']" :rows="$data['heatmap']" />
        </x-panel>

        <div class="grid gap-md lg:grid-cols-2 items-start">
            {{-- Red flags (thresholds agreed 23 Nov 2026: App\Support\RedFlags) --}}
            <x-panel title="Red flags" info="red_flags" subtitle="Early warnings, most flags first">
                <div class="overflow-x-auto -mx-1">
                    <table class="w-full min-w-[32rem] text-[0.8125rem]">
                        <thead>
                            <tr class="text-left">
                                <th class="{{ $th }}">Movement</th>
                                @foreach (\App\Support\RedFlags::CHECKS as $check)
                                    <th class="{{ $th }} text-center whitespace-nowrap" title="{{ $check['full'] }}">{{ $check['short'] }}</th>
                                @endforeach
                                <th class="{{ $th }} text-right">Flags</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['flags'] as $row)
                                <tr class="border-t border-[#f0eeea]">
                                    <td class="py-[7px] px-1"><a href="{{ route('movements.show', $row['movement']) }}" class="whitespace-nowrap {{ $link }}">{{ $short($row['movement']->name) }}</a></td>
                                    @foreach (\App\Support\RedFlags::CHECKS as $key => $check)
                                        @php($flag = $row['checks'][$key])
                                        <td class="py-[7px] px-1 text-center" title="{{ $check['full'] }}: {{ $flag === null ? 'cannot tell yet' : ($flag ? 'yes' : 'no') }}">
                                            @if ($flag === null)
                                                <span class="inline-block w-3 h-[2px] rounded bg-[#bdb9b0] align-middle" aria-hidden="true"></span><span class="sr-only">Cannot tell yet</span>
                                            @elseif ($flag)
                                                <span class="inline-block size-3 rounded-full bg-critical-ink align-middle" aria-hidden="true"></span><span class="sr-only">Flagged</span>
                                            @else
                                                <span class="inline-block size-3 rounded-full border-2 border-[#d9d6cf] align-middle" aria-hidden="true"></span><span class="sr-only">Clear</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td @class(['py-[7px] px-1 text-right font-bold tabular-nums', 'text-critical-ink' => $row['count'] >= 3])>{{ $row['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{-- What each column checks, in full: the headings stay one word so they never break. --}}
                <dl class="mt-md grid sm:grid-cols-2 gap-x-md gap-y-[6px] border-t border-[#f0eeea] pt-md text-[0.8125rem]">
                    @foreach (\App\Support\RedFlags::CHECKS as $check)
                        <div class="flex gap-xs"><dt class="font-semibold whitespace-nowrap">{{ $check['short'] }}:</dt><dd class="text-on-surface-variant">{{ $check['full'] }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-sm flex flex-wrap items-center gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant">
                    <span class="inline-flex items-center gap-xs"><span class="inline-block size-3 rounded-full bg-critical-ink" aria-hidden="true"></span>Flagged</span>
                    <span class="inline-flex items-center gap-xs"><span class="inline-block size-3 rounded-full border-2 border-[#d9d6cf]" aria-hidden="true"></span>Clear</span>
                    <span class="inline-flex items-center gap-xs"><span class="inline-block w-3 h-[2px] rounded bg-[#bdb9b0]" aria-hidden="true"></span>Cannot tell yet (no follow-up, or not on the form)</span>
                </p>
            </x-panel>

            <x-panel title="Most often missed" info="most_missed" :subtitle="'Points most movements still lose, across the '.$f['assessed'].' assessed · a guide for support across the region'">
                <ul class="flex flex-col gap-[12px] text-[0.875rem]">
                    @foreach ($data['missed'] as $item)
                        <li class="grid grid-cols-[minmax(0,1fr)_7rem_4.5rem] items-center gap-[12px]">
                            <span class="min-w-0"><span class="font-semibold">{{ $item['name'] }}</span> <span class="text-on-surface-variant">· {{ $item['category'] }}</span></span>
                            <span class="h-2 rounded-full bg-[#f0eeea] overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full bg-serious-ink" style="width: {{ $item['missed'] / max(1, $item['of']) * 100 }}%"></span></span>
                            <span class="text-right tabular-nums font-semibold">{{ $item['missed'] }} of {{ $item['of'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        </div>

        <div class="grid gap-md lg:grid-cols-[1.7fr_1fr] items-start">
            <x-panel title="Financial resilience"
                     :subtitle="'Ratios only, as currencies differ · '.collect($data['money'])->filter(fn ($r) => ($r['international'] ?? 0) > $data['limit'])->count().' of '.count($data['money']).' above the form’s '.$data['limit'].'% line for international funding'">
                <div class="overflow-x-auto -mx-1">
                    <table class="w-full min-w-[38rem] text-[0.875rem]">
                        <thead>
                            <tr class="text-left">
                                <th class="{{ $th }}">Movement</th>
                                <th class="{{ $th }} w-[32%]">International funding</th>
                                <th class="{{ $th }} text-right">Restricted</th>
                                <th class="{{ $th }} w-[24%]">Operating margin</th>
                                <th class="{{ $th }} text-right">Reserve</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['money'] as $r)
                                <tr class="border-t border-[#f0eeea]">
                                    <td class="py-[9px] px-1"><a href="{{ route('movements.show', $r['movement']) }}" class="{{ $link }}">{{ $short($r['movement']->name) }}</a></td>
                                    <td class="py-[9px] px-1">
                                        @if ($r['international'] !== null)
                                            <span class="flex items-center gap-sm">
                                                <span class="relative flex-1 h-2 rounded-full bg-[#f0eeea]">
                                                    <span class="absolute inset-y-0 left-0 rounded-full {{ $r['international'] > $data['limit'] ? 'bg-serious-ink' : 'bg-outline' }}" style="width: {{ min(100, $r['international']) }}%"></span>
                                                    <span class="absolute -top-1 -bottom-1 w-0.5 bg-on-surface" style="left: {{ $data['limit'] }}%" title="The form’s {{ $data['limit'] }}% line"></span>
                                                </span>
                                                <span class="w-11 text-right tabular-nums">{{ Viz::number($r['international']) }}%</span>
                                            </span>
                                        @else
                                            <span class="text-on-surface-variant">Not given</span>
                                        @endif
                                    </td>
                                    <td class="py-[9px] px-1 text-right tabular-nums">{{ $r['restricted'] !== null ? Viz::number($r['restricted']).'%' : '—' }}</td>
                                    <td class="py-[9px] px-1">
                                        @if ($r['margin'] !== null)
                                            @php($w = min(50, abs($r['margin']) * 2))
                                            <span class="flex items-center gap-sm">
                                                <span class="relative flex-1 h-2 rounded-full bg-[#f0eeea]">
                                                    <span class="absolute -top-1 -bottom-1 left-1/2 w-px bg-outline" aria-hidden="true"></span>
                                                    <span class="absolute inset-y-0 rounded-full {{ $r['margin'] < 0 ? 'bg-band-critical' : 'bg-info-ink' }}" style="{{ $r['margin'] < 0 ? 'right: 50%' : 'left: 50%' }}; width: {{ max(1, $w) }}%"></span>
                                                </span>
                                                <span class="w-12 text-right tabular-nums">{{ $r['margin'] > 0 ? '+' : '' }}{{ Viz::number($r['margin'], 1) }}%</span>
                                            </span>
                                        @else
                                            <span class="text-on-surface-variant">Not given</span>
                                        @endif
                                    </td>
                                    <td class="py-[9px] px-1 text-right tabular-nums whitespace-nowrap">{{ $r['reserve_months'] !== null ? Viz::number($r['reserve_months']).' months' : 'None' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>

            <x-panel title="By zone" subtitle="Every zone, whatever the filter">
                <div class="overflow-x-auto -mx-1">
                    <table class="w-full text-[0.875rem]">
                        <thead>
                            <tr class="text-left">
                                <th class="{{ $th }}">Zone</th>
                                <th class="{{ $th }} text-right">Assessed</th>
                                <th class="{{ $th }} text-right">Average</th>
                                <th class="{{ $th }} text-right">Change</th>
                                <th class="{{ $th }} text-right">At risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['zones'] as $z)
                                <tr class="border-t border-[#f0eeea]">
                                    <td class="py-[10px] px-1"><a href="{{ route('dashboard', ['zone' => $z['zone']->id]) }}" class="{{ $link }}">{{ $z['zone']->name }}</a></td>
                                    <td class="py-[10px] px-1 text-right tabular-nums text-on-surface-variant">{{ $z['assessed'] }} / {{ $z['movements'] }}</td>
                                    <td class="py-[10px] px-1 text-right tabular-nums font-semibold">{{ $z['mean'] !== null ? Viz::pct($z['mean']) : '—' }}</td>
                                    <td @class(['py-[10px] px-1 text-right tabular-nums font-semibold', 'text-good-ink' => ($z['change'] ?? 0) > 0])>{{ Viz::signed($z['change']) }}</td>
                                    <td @class(['py-[10px] px-1 text-right tabular-nums', 'font-bold text-critical-ink' => $z['at_risk'] > 0])>{{ $z['at_risk'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        </div>

        <div class="grid gap-md lg:grid-cols-2 items-start">
            <x-panel title="Least adopted policies and practices" info="adoption" subtitle="Share of assessed movements that have each one">
                <x-viz.adoption :rows="collect($data['adoption'])->map(fn ($r) => $r + ['label' => $r['label'].' · '.$r['kind']])->all()" labelWidth="15rem" />
            </x-panel>

            <div class="flex flex-col gap-md">
                <x-panel title="People and reach" subtitle="Added up across the movements that reported each figure">
                    <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-sm gap-y-md">
                        @foreach ([['staff', 'Staff'], ['volunteers', 'Volunteers'], ['beneficiaries', 'People reached a year'], ['board', 'Board members']] as [$key, $text])
                            <div>
                                <dt class="text-[0.78125rem] text-on-surface-variant">{{ $text }}</dt>
                                <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ $people[$key]['reporting'] > 0 ? Viz::number($people[$key]['total']) : '—' }}</dd>
                            </div>
                        @endforeach
                        @foreach ([['board_women', 'Women on Boards'], ['board_under_30', 'Under 30 on Boards']] as [$key, $text])
                            <div>
                                <dt class="text-[0.78125rem] text-on-surface-variant">{{ $text }}</dt>
                                <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ $boardShare($key) !== null ? $boardShare($key).'%' : '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-panel>

                @if ($data['cycles'] !== [])
                    <x-panel title="Finished cycles" info="plan_delivered" subtitle="Share of the ODP done by the end of the cycle, beside the points gained">
                        <ul class="flex flex-col gap-[12px] text-[0.875rem]">
                            @foreach ($data['cycles'] as $c)
                                <li class="grid grid-cols-[minmax(6rem,9rem)_1fr_4.5rem_3.5rem] items-center gap-[12px]">
                                    <a href="{{ route('movements.show', $c['movement']) }}" class="truncate {{ $link }}">{{ $short($c['movement']->name) }}</a>
                                    <span class="h-2 rounded-full bg-[#f0eeea] overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full bg-on-surface" style="width: {{ $c['plan'] ?? 0 }}%"></span></span>
                                    <span class="text-right tabular-nums text-on-surface-variant">{{ $c['plan'] !== null ? $c['plan'].'% done' : '—' }}</span>
                                    <span @class(['text-right tabular-nums font-bold', 'text-good-ink' => $c['change'] > 0])>{{ Viz::signed($c['change']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-panel>
                @endif
            </div>
        </div>

        {{-- The full detail, out of the way of the regional picture. --}}
        @php($detail = $data['detail'])
        <details class="group bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)]">
            <summary class="oha-summary flex items-center gap-md p-lg cursor-pointer list-none">
                <span class="flex-1 min-w-0">
                    <span class="block text-[1.125rem] font-bold">More detail</span>
                    <span class="block text-[0.875rem] text-on-surface-variant">Where the money comes from and goes, every policy and practice, Vision 2030.</span>
                </span>
                <span class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] transition-transform group-open:rotate-180" aria-hidden="true">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="px-lg pb-lg flex flex-col gap-lg">
                <div class="grid gap-lg lg:grid-cols-2 items-start border-t border-[#f0eeea] pt-lg">
                    @foreach ([['income_mix', Profile::INCOME_GROUPS, 'Where the money comes from', 'Average share of income by source (Q214–Q222).'], ['expense_mix', Profile::EXPENSE_GROUPS, 'Where the money goes', 'Average share of expenditure by type (Q229–Q237).']] as [$key, $groups, $title, $subtitle])
                        @php($avg = $detail['mixes'][$key])
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">{{ $title }}</h3><p class="text-[0.84375rem] text-on-surface-variant">{{ $subtitle }}</p></div>
                            @if ($avg === null)
                                <x-empty-state icon="pie_chart">No assessed movement gave these figures.</x-empty-state>
                            @else
                                <x-viz.stacked :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $avg['shares'][$k]])->values()->all()"
                                               :caption="'Average · '.$avg['movements'].' movements'" />
                                <div class="flex flex-col gap-sm border-t border-[#f0eeea] pt-sm">
                                    @foreach ($detail['profiles'] as $r)
                                        @continue(empty($r['profile'][$key]))
                                        <div class="grid grid-cols-[minmax(6rem,9rem)_1fr] items-center gap-sm">
                                            <span class="text-[0.8125rem] truncate">{{ $short($r['movement']->name) }}</span>
                                            <x-viz.stacked thin :legend="false" :segments="collect($groups)->map(fn ($g, $k) => ['label' => $g['label'], 'value' => $r['profile'][$key][$k]])->values()->all()" />
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                <div class="grid gap-lg lg:grid-cols-2 items-start border-t border-[#f0eeea] pt-lg">
                    <section class="flex flex-col gap-md">
                        <div><h3 class="text-[1rem] font-bold">Policies in place</h3><p class="text-[0.84375rem] text-on-surface-variant">Share of assessed movements with each policy (Q412–Q430).</p></div>
                        <x-viz.adoption :rows="$detail['policies']" />
                    </section>
                    <div class="flex flex-col gap-lg">
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">Governance practice</h3><p class="text-[0.84375rem] text-on-surface-variant">Share of assessed movements following each practice.</p></div>
                            <x-viz.adoption :rows="$detail['governance']" labelWidth="14rem" />
                        </section>
                        <section class="flex flex-col gap-md">
                            <div><h3 class="text-[1rem] font-bold">Vision 2030 in the Strategic Plan</h3><p class="text-[0.84375rem] text-on-surface-variant">Of the movements whose plan reflects Vision 2030 (Q620), how many cover each part.</p></div>
                            <x-viz.adoption :rows="$detail['vision']" />
                        </section>
                    </div>
                </div>
            </div>
        </details>

        <x-kpi-glossary :keys="['movements_assessed', 'average_score', 'current_score', 'baseline', 'change_since_baseline', 'improving', 'at_risk', 'health_band', 'evidence_backed', 'support_quadrants', 'red_flags', 'area_score', 'most_missed', 'region_median', 'international_funding', 'operating_margin', 'cash_reserve', 'adoption', 'plan_delivered']" />
    @endif
</x-layouts.app>
