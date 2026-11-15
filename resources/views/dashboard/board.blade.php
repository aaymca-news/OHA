@use('App\Support\Viz')
@use('App\Support\Bands')
{{--
    A National Movement's own dashboard, the same for its Board Chairperson and National
    General Secretary, analytics only (decided 15 Nov 2026): nothing about work, tasks or what
    is due (that is in My Work and the follow-up). Four key figures; our score over the cycle
    and where it can still grow; our areas against the baseline or the region; our money and
    people beside the region (its averages and medians only, never another movement's
    figures); and our contacts and documents.
--}}
@php
    $me = auth()->user();
    $firstName = \Illuminate\Support\Str::before($me->name, ' ');
    $vs = 'text-[0.78125rem] text-on-surface-variant';
@endphp
<x-layouts.app :title="$status->name" :subtitle="'Welcome back, '.$firstName.' · '.$me->role->label()">
    <x-slot:actions>
        @if ($data['rated'] && $status->band)
            <x-status-pill :tone="Bands::tone($status->band_code)" class="self-center">{{ $status->band->label }}</x-status-pill>
        @elseif ($underWay)
            <x-status-pill tone="info" class="self-center">Assessment started</x-status-pill>
        @else
            <x-status-pill class="self-center">Not yet assessed</x-status-pill>
        @endif
    </x-slot:actions>

    @if (! $data['rated'])
        <x-panel>
            <x-empty-state icon="insights">{{ $underWay ? 'Your assessment has started.' : 'Your movement is not assessed yet.' }} Your scores and figures appear here once your OHA form is approved.</x-empty-state>
        </x-panel>
    @else
        @php($d = $data)
        @php($w = $d['weakest'])
        {{-- Key figures --}}
        <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi label="Current score" :value="number_format($d['pct'], 1)" unit="%">
                @if ($d['followed'])
                    <span class="font-semibold text-good-ink">{{ Viz::signed($d['journey']['change']) }}</span> points since baseline ({{ Viz::pct($d['journey']['first']) }})
                @elseif ($d['journey'])
                    Your baseline · no follow-up score yet
                @else
                    From your approved OHA form
                @endif
            </x-kpi>
            <x-kpi label="Against the region" :value="Viz::signed($d['pct'] - $d['region']['mean'])" unit=" points">
                Regional average {{ Viz::pct($d['region']['mean']) }} · {{ $d['region']['movements'] }} movements
            </x-kpi>
            <x-kpi label="Strongest" :value="count($d['strongest']) > 1 ? count($d['strongest']).' areas' : ($d['strongest'][0] ?? '—')">
                {{ count($d['strongest']) > 1 ? 'At '.Viz::pct($d['strongest_pct'], 0).': '.implode(', ', $d['strongest']) : 'At '.Viz::pct($d['strongest_pct'], 0) }}
            </x-kpi>
            <x-kpi label="Weakest" :value="$w['short']" :tone="($w['band_code'] ?? null) && in_array($w['band_code'], \App\Enums\BandCode::NEEDING_SUPPORT, true) ? 'critical' : null">
                At {{ Viz::pct($w['pct'], 0) }} · {{ Viz::figure($w['lost']) }} of its {{ $w['max'] }} points still open
            </x-kpi>
        </section>

        <div class="grid gap-md lg:grid-cols-[1.4fr_1fr] items-start">
            <x-panel title="Our score over the cycle" :subtitle="$d['journey'] ? 'The baseline, then each follow-up score · regional average now '.Viz::pct($d['region']['mean']) : null">
                @if ($d['journey'])
                    <x-viz.growth-curve :points="$d['curve']" />
                @else
                    <x-empty-state icon="show_chart">Your baseline is set when your assessment is closed; each follow-up then adds a score.</x-empty-state>
                @endif
            </x-panel>

            <x-panel title="Where our score can grow" :subtitle="Viz::figure($d['points']['earned']).' of '.$d['points']['max'].' points earned · '.Viz::figure($d['points']['max'] - $d['points']['earned']).' still open'" stack>
                <span class="h-3 rounded-full bg-[#f0eeea] overflow-hidden" role="img" aria-label="{{ Viz::figure($d['points']['earned']) }} of {{ $d['points']['max'] }} points earned">
                    <span class="block h-full rounded-full bg-on-surface" style="width: {{ $d['points']['earned'] / max(1, $d['points']['max']) * 100 }}%"></span>
                </span>
                @if ($d['open'] === [])
                    <p><x-status-pill tone="good">Every point is earned</x-status-pill></p>
                @else
                    @php($openMax = max(1, collect($d['open'])->max('max')))
                    <ul class="flex flex-col gap-[12px] text-[0.875rem]">
                        @foreach ($d['open'] as $area)
                            <li class="grid grid-cols-[7rem_1fr_6.5rem] items-center gap-[12px]">
                                <span class="font-semibold truncate" title="{{ $area['name'] }}">{{ $area['short'] }}</span>
                                <span class="h-2 rounded-full bg-[#f0eeea] overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full bg-serious-ink" style="width: {{ $area['lost'] / $openMax * 100 }}%"></span></span>
                                <span class="text-right tabular-nums"><span class="font-semibold">{{ Viz::figure($area['lost']) }}</span> of {{ $area['max'] }} open</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="{{ $vs }}">Every other area has all its points.</p>
                @endif
            </x-panel>
        </div>

        {{-- Our areas: against the baseline (once there is one) or the region. --}}
        @php($hasBaseline = collect($d['areas'])->contains(fn ($a) => $a['baseline'] !== null))
        <x-panel title="Our areas" subtitle="Each area now, weakest first" x-data="{ against: '{{ $hasBaseline ? 'baseline' : 'region' }}' }">
            <x-slot:actions>
                <div class="flex gap-xs rounded-full bg-[#f2f1ee] p-[3px] text-[0.8125rem]" role="group" aria-label="Compare with">
                    @foreach (array_filter(['baseline' => $hasBaseline ? 'Against baseline' : null, 'region' => 'Against the region']) as $key => $text)
                        <button type="button" x-on:click="against = '{{ $key }}'" x-bind:aria-pressed="(against === '{{ $key }}').toString()"
                                x-bind:class="against === '{{ $key }}' ? 'bg-surface-container-lowest text-on-surface shadow-[0_1px_2px_rgba(20,20,20,0.08)]' : 'text-on-surface-variant hover:text-on-surface'"
                                class="min-h-8 px-[12px] rounded-full font-semibold">{{ $text }}</button>
                    @endforeach
                </div>
            </x-slot:actions>
            <ul class="flex flex-col gap-[14px] text-[0.875rem]">
                @foreach ($d['areas'] as $area)
                    @php($toBase = $area['baseline'] !== null ? round($area['pct'] - $area['baseline'], 1) : null)
                    @php($toRegion = $area['region'] !== null ? round($area['pct'] - $area['region'], 1) : null)
                    <li class="grid grid-cols-[minmax(0,1fr)_auto] sm:grid-cols-[8rem_1fr_3.5rem_9.5rem] items-center gap-x-[14px] gap-y-[6px]">
                        <span class="font-semibold truncate" title="{{ $area['name'] }}">{{ $area['short'] }}</span>
                        <span class="relative h-2.5 rounded-full bg-[#f0eeea] col-span-2 sm:col-span-1 row-start-2 sm:row-start-auto">
                            <span class="absolute inset-y-0 left-0 rounded-full {{ Bands::fill($area['band_code']) }}" style="width: {{ $area['pct'] }}%"></span>
                            @if ($area['baseline'] !== null)
                                <span x-show="against === 'baseline'" class="absolute -top-1.5 -bottom-1.5 w-[3px] -translate-x-1/2 rounded bg-on-surface" style="left: {{ $area['baseline'] }}%" title="Baseline {{ Viz::pct($area['baseline'], 0) }}"></span>
                            @endif
                            @if ($area['region'] !== null)
                                <span x-show="against === 'region'" @if ($hasBaseline) x-cloak @endif class="absolute -top-1.5 -bottom-1.5 w-[3px] -translate-x-1/2 rounded bg-on-surface" style="left: {{ $area['region'] }}%" title="Regional average {{ Viz::pct($area['region'], 0) }}"></span>
                            @endif
                        </span>
                        <span class="text-right font-semibold tabular-nums">{{ Viz::pct($area['pct'], 0) }}</span>
                        <span class="text-[0.8125rem] font-semibold tabular-nums">
                            <span x-show="against === 'baseline'" @class(['text-good-ink' => ($toBase ?? 0) > 0, 'text-critical-ink' => ($toBase ?? 0) < 0, 'text-on-surface-variant' => ! $toBase])>{{ $toBase === null ? '' : ($toBase == 0 ? 'No change' : Viz::signed($toBase).' since baseline') }}</span>
                            <span x-show="against === 'region'" @if ($hasBaseline) x-cloak @endif @class(['text-good-ink' => ($toRegion ?? 0) > 0, 'text-critical-ink' => ($toRegion ?? 0) < 0, 'text-on-surface-variant' => ! $toRegion])>{{ $toRegion === null ? '' : ($toRegion == 0 ? 'Level with the region' : Viz::signed($toRegion).' vs region') }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-md flex flex-wrap gap-x-md gap-y-xs {{ $vs }}">
                <span class="inline-flex items-center gap-xs"><span class="w-4 h-2.5 rounded-full bg-band-strong" aria-hidden="true"></span>Now, in the band’s colour</span>
                <span class="inline-flex items-center gap-xs"><span class="w-[3px] h-3.5 rounded bg-on-surface" aria-hidden="true"></span><span x-text="against === 'baseline' ? 'Baseline' : 'Regional average'">{{ $hasBaseline ? 'Baseline' : 'Regional average' }}</span></span>
            </p>
        </x-panel>

        @php($money = $d['money'])
        @php($people = $d['people'])
        <div class="grid gap-md lg:grid-cols-2 items-start">
            <x-panel title="Money" subtitle="From our form as it stands · beside the regional median">
                <dl class="grid grid-cols-2 gap-x-md gap-y-[18px]">
                    @foreach ([
                        ['International funding', $money['international'], $money['region']['international'], ($money['international'] ?? 0) > $money['limit'] ? 'Above the form’s '.$money['limit'].'% line' : null],
                        ['Restricted funding', $money['restricted'], $money['region']['restricted'], null],
                    ] as [$label, $value, $region, $note])
                        <div>
                            <dt class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $label }}</dt>
                            <dd @class(['mt-0.5 text-[1.375rem] font-bold tabular-nums', 'text-serious-ink' => $note !== null])>{{ $value !== null ? Viz::number($value).'%' : '—' }}</dd>
                            <dd class="{{ $vs }}">{{ $note ? $note.' · ' : '' }}Region {{ $region !== null ? Viz::number($region).'%' : '—' }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Operating margin</dt>
                        <dd @class(['mt-0.5 text-[1.375rem] font-bold tabular-nums', 'text-critical-ink' => ($money['margin'] ?? 0) < 0])>{{ $money['margin'] !== null ? ($money['margin'] > 0 ? '+' : '').Viz::number($money['margin'], 1).'%' : '—' }}</dd>
                        <dd class="{{ $vs }}">{{ ($money['margin'] ?? 0) < 0 ? 'A deficit · ' : '' }}Region {{ $money['region']['margin'] !== null ? ($money['region']['margin'] > 0 ? '+' : '').Viz::number($money['region']['margin'], 1).'%' : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Cash reserve</dt>
                        <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ $money['reserve_months'] !== null ? Viz::number($money['reserve_months']).' months' : 'None' }}</dd>
                        <dd class="{{ $vs }}">{{ $money['region']['with_reserve'] }} of {{ $d['region']['movements'] }} movements have one</dd>
                    </div>
                </dl>
            </x-panel>

            <x-panel title="People, Board and policies" subtitle="From our form as it stands · beside the region">
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-md gap-y-[18px]">
                    @foreach ([
                        ['Staff', $people['staff'], 'Median '.Viz::number($people['region']['staff'])],
                        ['Volunteers', $people['volunteers'], 'Median '.Viz::number($people['region']['volunteers'])],
                        ['Board members', $people['board'], 'Median '.Viz::number($people['region']['board'])],
                        ['Women on the Board', $people['women'] !== null ? $people['women'].'%' : null, 'Region '.($people['region']['women'] ?? '—').'%'],
                        ['Under 30 on the Board', $people['under_30'] !== null ? $people['under_30'].'%' : null, 'Region '.($people['region']['under_30'] ?? '—').'%'],
                        ['Policies in place', $people['policies'].' / '.$people['policies_of'], 'Region average '.Viz::number($people['region']['policies'], 1)],
                    ] as [$label, $value, $region])
                        <div>
                            <dt class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $label }}</dt>
                            <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ $value === null ? '—' : (is_string($value) ? $value : Viz::number($value)) }}</dd>
                            <dd class="{{ $vs }}">{{ $region }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        </div>
    @endif

    {{-- Contacts · documents --}}
    <div class="grid gap-md lg:grid-cols-2 items-start">
        <x-panel title="Your contacts at AAYMCA" subtitle="The staff assessing your movement">
            @forelse ($assessors as $person)
                <div class="flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0">
                    <x-avatar :user="$person" class="!size-[38px]" />
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold">{{ $person->name }}</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant truncate">{{ $person->title ?? $person->role->label() }}</span>
                    </span>
                    <a href="mailto:{{ $person->email }}" title="{{ $person->email }}" aria-label="Email {{ $person->name }} ({{ $person->email }})"
                       class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] hover:bg-[#f2f1ee] hover:text-on-surface">
                        <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/></svg>
                    </a>
                </div>
            @empty
                <x-empty-state icon="support_agent">AAYMCA has not yet named who assesses your movement.</x-empty-state>
            @endforelse
        </x-panel>

        <x-panel title="Reports and ODPs" subtitle="Everything approved for your movement">
            @forelse ($assessments as $assessment)
                <a href="{{ route('assessments.show', $assessment) }}" class="flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0 rounded-lg text-on-surface no-underline group">
                    <span class="flex-none size-[38px] rounded-[10px] bg-[#f2f1ee] grid place-items-center text-[#4a4950]">
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/></svg>
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold group-hover:underline">{{ $assessment->period_label }} assessment</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant truncate">
                            @if ($assessment->score)
                                Report, ODP and scores · {{ $assessment->score->band?->label }}, {{ number_format((float) $assessment->score->pct, 1) }}%
                            @else
                                {{ \App\Enums\Stage::of($assessment)->label() }}
                            @endif
                        </span>
                    </span>
                    <svg class="size-4 flex-none text-[#8a8880]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
            @empty
                <x-empty-state icon="folder_open">No report or ODP has been approved for your movement yet.</x-empty-state>
            @endforelse
        </x-panel>
    </div>
</x-layouts.app>
