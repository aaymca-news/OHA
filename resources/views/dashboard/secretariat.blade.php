@use('App\Oha\Profile')
@use('App\Support\Viz')
@use('App\Support\Bands')
{{--
    AAYMCA's dashboard (staff, Administrators and Super Administrators alike), analytics only
    (decided 15 Nov 2026): nothing about work, tasks or what is due, which live in My Work,
    Timelines and the follow-ups. For the region or one zone: the key figures; who needs
    support; the bands then and now; growth over the cycle and the biggest gains; every area
    for every movement; the points most often missed and the weakest areas; money; the zones;
    the least adopted policies, people, and what finished cycles delivered. The full detail
    (funding mixes, every policy and practice, Vision 2030) is folded away at the end.
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
    <x-segmented label="Zone">
        <x-segmented.link :href="route('dashboard')" :active="$zone === null">All zones</x-segmented.link>
        @foreach ($zones as $z)
            <x-segmented.link :href="route('dashboard', ['zone' => $z->id])" :active="$zone?->id === $z->id">{{ $z->name }}</x-segmented.link>
        @endforeach
    </x-segmented>

    {{-- Key figures --}}
    <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-5">
        <x-kpi label="Movements assessed" :value="$f['assessed']" :unit="' / '.$f['total']">
            <x-slot:visual><x-progress-ring :value="$f['assessed']" :max="max(1, $f['total'])" :size="64" /></x-slot:visual>
            {{ $f['total'] - $f['assessed'] }} not assessed yet
        </x-kpi>
        <x-kpi label="Average current score" :value="$f['mean'] !== null ? number_format((float) $f['mean'], 1) : '—'" :unit="$f['mean'] !== null ? '%' : null">
            @if ($meanBand)
                <x-slot:badge><x-status-pill :tone="Bands::tone($meanBand['code'])">{{ $meanBand['label'] }}</x-status-pill></x-slot:badge>
            @endif
            @if ($f['mean_change'] !== null)
                <span class="font-semibold text-good-ink">{{ Viz::signed($f['mean_change']) }}</span> points since baseline, on average
            @else
                No follow-up score yet
            @endif
        </x-kpi>
        <x-kpi label="Improving" :value="$f['improved']" :unit="' of '.$f['followed']">
            followed up · {{ $f['band_up'] }} moved up a band
        </x-kpi>
        <x-kpi label="At Risk or Critical" :value="$f['at_risk']" :tone="$f['at_risk'] > 0 ? 'critical' : null">
            {{ $f['at_risk_baseline'] }} at baseline
        </x-kpi>
        <x-kpi label="Backed by evidence" :value="$f['evidence'] !== null ? $f['evidence'] : '—'" :unit="$f['evidence'] !== null ? '%' : null">
            {{ $f['evidence_yes'] > 0 ? 'of '.$f['evidence_yes'].' “Yes” answers evidence can prove' : 'How far the scores are proven' }}
        </x-kpi>
    </section>

    @if ($f['assessed'] === 0)
        <x-panel>
            <x-empty-state icon="insights">No movement {{ $zone ? 'in '.$zone->name.' ' : '' }}is assessed yet. Its figures appear here once its first OHA form is approved.</x-empty-state>
        </x-panel>
    @else
        <div class="grid gap-md lg:grid-cols-[1.7fr_1fr] items-start">
            <x-panel title="Who needs support" subtitle="Current score against the change since baseline. Hollow: no follow-up yet.">
                <x-viz.support-matrix :matrix="$data['matrix']" />
            </x-panel>

            <x-panel title="Health bands" subtitle="Movements with a baseline: then and now" stack>
                @php($bandMax = max(1, collect($data['bands'])->max(fn ($b) => max($b['baseline'], $b['now']))))
                <ul class="flex flex-col gap-md">
                    @foreach ($data['bands'] as $row)
                        <li class="flex flex-col gap-[6px] text-[0.875rem]">
                            <span class="flex items-center justify-between gap-sm">
                                <x-status-pill :tone="Bands::tone($row['band']->code)">{{ $row['band']->label }}</x-status-pill>
                                <span class="tabular-nums text-on-surface-variant">{{ $row['baseline'] }} → <span class="font-bold text-on-surface">{{ $row['now'] }}</span></span>
                            </span>
                            <span class="h-1.5 rounded-full bg-[#f0eeea] overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full bg-outline-variant" style="width: {{ $row['baseline'] / $bandMax * 100 }}%"></span></span>
                            <span class="h-2.5 rounded-full bg-[#f0eeea] overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full {{ Bands::fill($row['band']->code) }}" style="width: {{ $row['now'] / $bandMax * 100 }}%"></span></span>
                        </li>
                    @endforeach
                </ul>
                <p class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant">
                    <span class="inline-flex items-center gap-xs"><span class="w-4 h-1.5 rounded-full bg-outline-variant" aria-hidden="true"></span>Baseline</span>
                    <span class="inline-flex items-center gap-xs"><span class="w-4 h-2.5 rounded-full bg-on-surface" aria-hidden="true"></span>Now, in the band’s colour</span>
                </p>
            </x-panel>
        </div>

        @if ($data['growth']['lines'] !== [])
            <div class="grid gap-md lg:grid-cols-[1.7fr_1fr] items-start">
                <x-panel title="Growth over the cycle" subtitle="Each movement followed up, from its baseline to its newest follow-up score">
                    <x-slot:actions><x-more-link :href="route('journeys.index')">Every journey</x-more-link></x-slot:actions>
                    <x-viz.growth-lines :lines="$data['growth']['lines']" :slots="$data['growth']['slots']" />
                </x-panel>

                <x-panel title="Biggest gains" subtitle="Points since baseline">
                    <ul class="flex flex-col">
                        @foreach ($data['gains'] as $m)
                            <li class="flex items-center gap-[12px] py-[10px] border-t border-[#f0eeea] first:border-t-0 first:pt-0 text-[0.875rem]">
                                <span class="flex-1 min-w-0">
                                    <a href="{{ route('movements.show', $m['movement']) }}" class="block truncate {{ $link }}">{{ $short($m['movement']->name) }}</a>
                                    <span class="block text-[0.78125rem] text-on-surface-variant tabular-nums">{{ Viz::pct($m['journey']['first']) }} → {{ Viz::pct($m['journey']['now']) }}</span>
                                </span>
                                @if ($m['band'])
                                    <x-status-pill :tone="Bands::tone($m['band']->code)">{{ $m['band']->label }}</x-status-pill>
                                @endif
                                <span @class(['w-14 text-right font-bold tabular-nums', 'text-good-ink' => $m['change'] > 0, 'text-critical-ink' => $m['change'] < 0])>{{ Viz::signed($m['change']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-panel>
            </div>
        @endif

        <x-panel title="Every movement, every area" subtitle="Hover an area for its full name">
            <x-viz.heatmap :categories="$data['categories']" :rows="$data['heatmap']" />
        </x-panel>

        <div class="grid gap-md lg:grid-cols-2 items-start">
            <x-panel title="Most often missed" :subtitle="'Points most movements still lose, across the '.$f['assessed'].' assessed · a guide for support across the region'">
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

            <x-panel title="Areas, weakest first" subtitle="Average current score in each area">
                <x-score-bars label-width="8rem" :rows="collect($data['areas'])->map(fn ($a) => [
                    'label' => $a['category']->short_name, 'title' => $a['category']->name, 'pct' => $a['pct'],
                    'band_code' => Bands::at($a['pct'])['code'] ?? null, 'band' => Bands::at($a['pct'])['label'] ?? '—',
                ])->all()" />
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
            <x-panel title="Least adopted policies and practices" subtitle="Share of assessed movements that have each one">
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
                    <x-panel title="Finished cycles" subtitle="Share of the ODP done by the end of the cycle, beside the points gained">
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
    @endif
</x-layouts.app>
