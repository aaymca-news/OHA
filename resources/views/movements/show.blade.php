@use('App\Support\Viz')
@use('App\Support\Bands')
{{--
    One National Movement's page (decided 23 Nov 2026), the same for everyone who may see it:
    its Board Chairperson and National General Secretary reach it as their dashboard, AAYMCA
    staff from the National Movements. The dashboard is analytics only (decided 15 Nov 2026),
    and about this movement only: no regional average, median or comparison anywhere on it
    (decided 27 Nov 2026; the region's figures are on AAYMCA's dashboard). The key figures;
    the health shape and the score over the cycle; where it can still grow; its money, people
    and policies; the cycle's journey and the scores over time for
    those allowed; the movement at a glance, from its form; its Stage 1 documents (the OHA
    assessment form, report and ODP as approved, and when); who leads it and its contacts; and
    how each figure is worked out. AAYMCA staff also have the Manage section at the foot: the
    next assessment, the evidence, the cycle, the ODP follow-up, the assessors and the
    assessments (starting one too). Data: App\Queries\MovementPage.
--}}
@php
    $me = auth()->user();
    $paused = $movement->followUpPaused();
    $short = fn (string $name) => str_replace(' YMCA', '', $name);
    $label = 'text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant';
    $vs = 'text-[0.78125rem] text-on-surface-variant';
    $d = $data;
    $subtitle = $own ? 'Welcome back, '.\Illuminate\Support\Str::before($me->name, ' ').' · '.$me->role->label() : null;
@endphp
<x-layouts.app :title="$movement->name" :subtitle="$subtitle">
    {{-- Where it is (27 Nov 2026): its National Head Office, its zone and its membership, the same for everyone. --}}
    <x-slot:meta>
        <div class="flex flex-wrap items-center gap-x-md gap-y-sm">
            <x-head-office :movement="$movement" size="lg" />
            {{-- The zone and the membership move together when the line wraps. --}}
            <span class="inline-flex flex-wrap items-center gap-sm">
                <span class="inline-flex items-center gap-[6px] text-[0.875rem] text-on-surface-variant">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">public</span>{{ $movement->zone->name }}
                </span>
                <x-status-pill>{{ $movement->membershipStatus->label }}</x-status-pill>
            </span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            <x-status-pill :tone="$stage->tone()">{{ $stage->label() }}</x-status-pill>
            {{-- The movement's evidence library lives here, on its page, not in the menu (27 Nov 2026). --}}
            @can('viewLibrary', [\App\Models\Evidence::class, $movement])
                <x-row-action :href="route('evidence.index', $movement)" :title="__('oha.nav.evidence_library', ['movement' => $movement->name])">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">folder_open</span>Evidence Library
                </x-row-action>
            @endcan
            @if ($data['rated'])
                <x-download-menu :pdf="route('movements.report', [$movement, 'pdf'])" :xlsx="route('movements.report', [$movement, 'xlsx'])" />
            @endif
            @if ($manage)
                <x-row-action href="#manage">
                    Manage
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </x-row-action>
            @endif
            @can('update', $movement)
                <x-row-action :href="route('admin.movements.edit', $movement)">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit</span>Edit details
                </x-row-action>
            @endcan
        </div>
    </x-slot:actions>

    {{-- Only when its assessor can begin: one line, one button (to the Manage section). --}}
    @if ($manage && $canOpen && ! $underWay && ! ($lastDone && $followingUp))
        <x-action-banner :title="$status->latest_assessment_id ? $short($movement->name).'’s cycle is complete: it is ready for its next assessment' : $short($movement->name).' has not been assessed yet'"
                         href="#start" button="Start the assessment" />
    @endif

    @if (! $d['rated'])
        <x-panel>
            <x-empty-state icon="insights">
                @if ($underWay)
                    <x-status-pill tone="info">Assessment started</x-status-pill>
                @endif
                {{ $own ? 'Your movement has no approved OHA form yet.' : $movement->name.' has no approved OHA form yet.' }} The scores and figures appear here once the form is approved.
            </x-empty-state>
        </x-panel>
    @else
        {{-- The weakest areas: every one of them when several tie (27 Nov 2026); the first opens the health shape. --}}
        @php($weak = $d['weakest'])
        @php($w = $weak[0])
        {{-- Key figures --}}
        {{-- As many tiles a row as fit (at least 14rem each); a short last row widens to fill it, so no gap is left. --}}
        <section aria-label="Key figures" class="flex flex-wrap gap-md *:flex-[1_1_14rem]">
            <x-kpi label="Current score" info="current_score" :value="number_format($d['pct'], 1)" unit="%">
                @if ($status->band)
                    <x-slot:badge><x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill></x-slot:badge>
                @endif
                @if ($d['followed'])
                    <span @class(['font-semibold', 'text-good-ink' => $d['journey']['change'] > 0, 'text-critical-ink' => $d['journey']['change'] < 0])>{{ Viz::signed($d['journey']['change']) }}</span> points since baseline ({{ Viz::pct($d['journey']['first']) }})
                @elseif ($d['journey'])
                    The baseline · no follow-up score yet
                @else
                    From the approved OHA form
                @endif
                <x-band-scale :pct="$d['pct']" class="mt-1" />
            </x-kpi>
            <x-kpi label="Strongest" info="strongest_weakest" :value="Viz::tiedFigure($d['strongest'])">
                {{ count($d['strongest']) > 1 ? 'All at '.Viz::pct($d['strongest_pct'], 0).': '.Viz::names($d['strongest']) : 'At '.Viz::pct($d['strongest_pct'], 0) }}
            </x-kpi>
            <x-kpi label="Weakest" info="strongest_weakest" :value="Viz::tiedFigure(array_column($weak, 'short'))" :tone="($w['band_code'] ?? null) && in_array($w['band_code'], \App\Enums\BandCode::NEEDING_SUPPORT, true) ? 'critical' : null">
                @if (count($weak) > 1)
                    All at {{ Viz::pct($w['pct'], 0) }}: {{ Viz::names(array_column($weak, 'short')) }} · {{ Viz::figure(array_sum(array_column($weak, 'lost'))) }} points still open between them
                @else
                    At {{ Viz::pct($w['pct'], 0) }} · {{ Viz::figure($w['lost']) }} of its {{ $w['max'] }} points still open
                @endif
            </x-kpi>
        </section>

        @php($shape = $d['shape'])
        @php($axisStart = collect($shape)->search(fn ($a) => $a['code'] === ($w['code'] ?? null)))
        @php($axisStart = $axisStart === false ? 0 : $axisStart)
        {{-- The baseline is drawn only once a follow-up has moved the score: before that it is the score now, and would hide under it. --}}
        @php($hasBaseline = $d['followed'] && collect($shape)->contains(fn ($a) => $a['baseline'] !== null))
        <div class="grid gap-md lg:grid-cols-2 items-start">
            {{-- The health shape (23 Nov 2026): the 8 areas as an octagon, now and when the cycle began. --}}
            <x-panel title="Health shape" info="area_score" subtitle="The 8 scored areas. Rings at 20, 50, 70 and 90%: where the bands begin. Hover a corner."
                     x-data="{ axis: {{ $axisStart }} }">
                @if ($latest && $me->can('view', $latest))
                    <x-slot:actions><x-more-link :href="route('assessments.show', $latest)">Full scores</x-more-link></x-slot:actions>
                @endif
                <x-viz.radar :areas="$shape" :label="'Health shape: '.collect($shape)->map(fn ($a) => $a['short'].' '.Viz::pct($a['pct'], 0))->join(', ')">
                    @if ($hasBaseline)
                        <polygon points="{{ \App\Support\Radar::polygon(array_column($shape, 'baseline')) }}" fill="none" stroke="#d9822b" stroke-width="2" stroke-dasharray="6 4" stroke-linejoin="round" />
                    @endif
                    <polygon points="{{ \App\Support\Radar::polygon(array_column($shape, 'pct')) }}" fill="#2f6fb0" fill-opacity="0.16" stroke="#2f6fb0" stroke-width="2.2" stroke-linejoin="round" />
                    @foreach ($shape as $k => $area)
                        @php([$px, $py] = \App\Support\Radar::point($k, count($shape), (float) $area['pct'] / 100 * \App\Support\Radar::RADIUS))
                        <circle cx="{{ $px }}" cy="{{ $py }}" r="3.5" fill="#2f6fb0" />
                    @endforeach
                </x-viz.radar>
                {{-- The key and the figures together: each line of the shape with its value for the corner picked (the weakest to start with). --}}
                @foreach ($shape as $k => $area)
                    @php($change = $area['baseline'] !== null ? round($area['pct'] - $area['baseline'], 1) : null)
                    <div x-show="axis === {{ $k }}" @if ($k !== $axisStart) x-cloak @endif class="mt-sm flex flex-col gap-sm border-t border-[#f0eeea] pt-md">
                        <div>
                            <p class="text-[0.9375rem] font-bold">{{ $area['name'] }}</p>
                            <p class="text-[0.8125rem] text-on-surface-variant">
                                Pick another corner of the shape to see its figures.
                                @if ($hasBaseline)
                                    The colours match the shape: blue now, dashed orange when the cycle began.
                                @elseif ($d['journey'])
                                    No follow-up yet, so the score now is the baseline; each follow-up then shows how far it has moved.
                                @endif
                            </p>
                        </div>
                        {{-- Each figure in a column, the columns sharing rows (label, value, note), so the values sit level even when a label wraps. --}}
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-sm gap-y-md sm:gap-y-[2px]">
                            {{-- Plain words (24 Nov 2026): what each figure is, and how it compares; each value in its line's colour. --}}
                            <div class="sm:grid sm:grid-rows-subgrid sm:row-span-3">
                                <dt class="text-[0.8125rem] font-semibold text-on-surface-variant">Now</dt>
                                <dd class="text-[1.25rem] font-bold tabular-nums text-[#2f6fb0]">{{ Viz::pct($area['pct'], 0) }}</dd>
                                <dd class="{{ $vs }}">{{ Viz::figure($area['points']) }} of its {{ $area['max'] }} points earned</dd>
                            </div>
                            @if ($hasBaseline)
                                <div class="sm:grid sm:grid-rows-subgrid sm:row-span-3">
                                    <dt class="text-[0.8125rem] font-semibold text-on-surface-variant">When the cycle began</dt>
                                    <dd class="text-[1.25rem] font-bold tabular-nums text-[#a8590f]">{{ $area['baseline'] !== null ? Viz::pct($area['baseline'], 0) : '—' }}</dd>
                                    @if ($change !== null)
                                        <dd @class([$vs, '!text-good-ink font-semibold' => $change > 0, '!text-critical-ink font-semibold' => $change < 0])>{{ $change == 0 ? 'No change since' : ($change > 0 ? 'Up ' : 'Down ').Viz::figure(abs($change), 1).' points since' }}</dd>
                                    @endif
                                </div>
                            @endif
                        </dl>
                    </div>
                @endforeach
            </x-panel>

            <x-panel title="Score over the cycle" info="baseline" :subtitle="$d['journey'] ? 'The baseline, then each follow-up score, against the bands' : null">
                @if ($d['journey'])
                    <x-viz.growth-curve :points="$d['curve']" :height="240" shaded :pace="$d['pace']['points'] ?? null" />
                    @if ($d['pace'])
                        @php($pace = $d['pace'])
                        <div class="mt-md flex items-start gap-sm rounded-xl bg-[#fdf3f4] px-[14px] py-[12px] text-[0.84375rem]">
                            <span class="flex-none mt-[5px] size-2.5 rounded-full border-2 border-[#c8102e]" aria-hidden="true"></span>
                            <p>
                                <span class="inline-flex items-center gap-[4px] font-semibold">Pace to {{ $pace['band'] }}<x-kpi-info key="pace_needed" /></span>
                                reaching {{ Viz::figure($pace['target']) }}% by the end of the cycle needs about {{ Viz::signed($pace['need']) }} points at each of the {{ $pace['left'] }} {{ \Illuminate\Support\Str::plural('follow-up', $pace['left']) }} left.
                                @if ($pace['gained'] !== null)
                                    It gained {{ Viz::signed($pace['gained']) }} per follow-up so far{{ $pace['on_course'] ? ', so it is on course.' : ', so it needs to speed up.' }}
                                @endif
                            </p>
                        </div>
                    @endif
                @else
                    <x-empty-state icon="show_chart">The baseline is set when the assessment is closed; each follow-up then adds a score.</x-empty-state>
                @endif
            </x-panel>
        </div>

        @php($money = $d['money'])
        @php($people = $d['people'])
        <div class="grid gap-md lg:grid-cols-2 items-start">
            {{-- Where the missing points are: a waterfall from the points earned to full marks. --}}
            <x-panel title="Where the missing points are" info="points_open"
                     :subtitle="'From the '.Viz::figure($d['points']['earned']).' points earned to the full '.$d['points']['max'].': each step is an area’s points still open, biggest first'">
                @if ($d['open'] === [])
                    <p><x-status-pill tone="good">Every point is earned</x-status-pill></p>
                @else
                    @php($full = max(1, $d['points']['max']))
                    @php($cum = $d['points']['earned'])
                    <div class="grid grid-cols-[6.5rem_1fr_3.5rem] items-center gap-x-[12px] gap-y-[8px] text-[0.8125rem]">
                        <span class="font-bold">Earned</span>
                        <span class="relative h-4 rounded bg-[#f6f5f2]"><span class="absolute inset-y-0 left-0 rounded bg-on-surface" style="width: {{ $d['points']['earned'] / $full * 100 }}%"></span></span>
                        <span class="text-right font-bold tabular-nums">{{ Viz::figure($d['points']['earned']) }}</span>
                        @foreach ($d['open'] as $area)
                            <span class="font-semibold truncate" title="{{ $area['name'] }}">{{ $area['short'] }}</span>
                            <span class="relative h-4 rounded bg-[#f6f5f2]" role="img" aria-label="{{ $area['name'] }}: {{ Viz::figure($area['lost']) }} of its {{ $area['max'] }} points still open"><span class="absolute inset-y-0 rounded bg-band-atrisk" style="left: {{ $cum / $full * 100 }}%; width: {{ $area['lost'] / $full * 100 }}%"></span></span>
                            <span class="text-right font-bold tabular-nums text-serious-ink">+{{ Viz::figure($area['lost']) }}</span>
                            @php($cum += $area['lost'])
                        @endforeach
                        <span class="font-bold">Full marks</span>
                        <span class="relative h-4 rounded border-[1.5px] border-on-surface"></span>
                        <span class="text-right font-bold tabular-nums">{{ $d['points']['max'] }}</span>
                    </div>
                    <p class="mt-md {{ $vs }}">The longest steps are where the score can grow most. Every other area has all its points.</p>
                @endif
            </x-panel>

            <x-panel title="Money" info="international_funding" subtitle="From the form as it stands · the last financial year">
                @php($groups = \App\Oha\Profile::INCOME_GROUPS)
                @php($colors = ['#2f6fb0', '#7a5cc2', '#2e9e83', '#d9822b', '#c8433b', '#9a978f'])
                @php($ourSum = array_sum($money['mix'] ?? []) ?: 1)
                @if ($money['mix'])
                    <p class="{{ $label }}">Where the money comes from</p>
                    <span class="mt-sm flex h-[18px] rounded-[5px] overflow-hidden" role="img" aria-label="Where the money comes from: {{ collect($groups)->map(fn ($g, $k) => $g['label'].' '.round(($money['mix'][$k] ?? 0) * 100 / $ourSum).'%')->join(', ') }}">
                        @foreach ($groups as $k => $g)<span style="width: {{ ($money['mix'][$k] ?? 0) * 100 / $ourSum }}%; background: {{ $colors[$loop->index] }}" title="{{ $g['label'] }} {{ round(($money['mix'][$k] ?? 0) * 100 / $ourSum) }}%"></span>@endforeach
                    </span>
                    <p class="mt-sm flex flex-wrap gap-x-md gap-y-xs {{ $vs }}">
                        @foreach ($groups as $k => $g)
                            <span class="inline-flex items-center gap-[6px]"><span class="size-2.5 rounded-[3px]" style="background: {{ $colors[$loop->index] }}" aria-hidden="true"></span>{{ $g['label'] }} {{ round(($money['mix'][$k] ?? 0) * 100 / $ourSum) }}%</span>
                        @endforeach
                    </p>
                @endif

                <p class="mt-lg {{ $label }}">International funding</p>
                @if ($money['international'] !== null)
                    <div class="relative mt-sm h-10" role="img" aria-label="International funding {{ Viz::number($money['international']) }}% of income; the form’s line at {{ $money['limit'] }}%">
                        <div class="h-3 rounded-full bg-[#f0eeea] overflow-hidden"><div @class(['h-full rounded-full', 'bg-band-atrisk' => $money['international'] > $money['limit'], 'bg-band-developing' => $money['international'] <= $money['limit']]) style="width: {{ min(100, $money['international']) }}%"></div></div>
                        <span class="absolute -top-[5px] h-[22px] border-l-2 border-band-critical" style="left: {{ $money['limit'] }}%"></span>
                        <span class="absolute top-[20px] -translate-x-1/2 text-[0.75rem] text-critical-ink whitespace-nowrap" style="left: {{ $money['limit'] }}%">Form’s {{ $money['limit'] }}% line</span>
                    </div>
                    <p class="{{ $vs }}"><span class="font-semibold text-on-surface">{{ Viz::number($money['international']) }}% of income</span> · above {{ $money['limit'] }}% the form withholds its point</p>
                @else
                    <p class="{{ $vs }}">Not given on the form.</p>
                @endif

                <dl class="mt-lg grid grid-cols-3 gap-x-md gap-y-sm">
                    <div>
                        <dt class="{{ $label }}">Restricted</dt>
                        <dd class="mt-0.5 text-[1.25rem] font-bold tabular-nums">{{ $money['restricted'] !== null ? Viz::number($money['restricted']).'%' : '—' }}</dd>
                        <dd class="{{ $vs }}">Of income, tied to a purpose</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Operating margin</dt>
                        <dd @class(['mt-0.5 text-[1.25rem] font-bold tabular-nums', 'text-critical-ink' => ($money['margin'] ?? 0) < 0])>{{ $money['margin'] !== null ? ($money['margin'] > 0 ? '+' : '').Viz::number($money['margin'], 1).'%' : '—' }}</dd>
                        <dd class="{{ $vs }}">{{ $money['margin'] === null ? 'Not given on the form' : ($money['margin'] < 0 ? 'A deficit' : 'Income left after costs') }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Cash reserve</dt>
                        <dd class="mt-0.5 text-[1.25rem] font-bold tabular-nums">{{ $money['reserve_months'] !== null ? Viz::number($money['reserve_months']).' months' : 'None' }}</dd>
                        <dd class="{{ $vs }}">{{ $money['reserve_months'] !== null ? 'Of costs, covered by savings' : 'No cash reserve' }}</dd>
                    </div>
                </dl>
            </x-panel>
        </div>

        {{-- The 19 policies the form asks about. --}}
        <x-panel title="Policies in place" info="policies_in_place" :subtitle="$people['policies'].' of the '.$people['policies_of'].' the form asks about'">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(12.5rem,1fr))] gap-sm">
                @foreach ($d['policies'] as $policy)
                    <div @class(['flex flex-col gap-[4px] rounded-xl border px-[12px] py-[10px] text-[0.8125rem]', 'border-[#f0eeea] bg-[#fafaf8]' => $policy['ours'] === true, 'border-dashed border-[#dcd8d0]' => $policy['ours'] !== true])>
                        <span class="flex items-center gap-sm">
                            <span @class(['flex-none size-[14px] rounded', 'bg-on-surface' => $policy['ours'] === true, 'border-[1.5px] border-dashed border-[#bdb9b0]' => $policy['ours'] !== true]) aria-hidden="true"></span>
                            <span class="font-semibold">{{ $policy['label'] }}</span>
                        </span>
                        <span class="text-[0.75rem] text-on-surface-variant">{{ $policy['ours'] === true ? 'In place' : ($policy['ours'] === false ? 'Not yet' : 'Not answered on the form') }}</span>
                    </div>
                @endforeach
            </div>
        </x-panel>

        <x-panel title="People and Board" info="board_make_up" subtitle="From the form as it stands">
            @php($identity = $profile['identity'] ?? [])
            @php($federative = ($identity['structure'] ?? null) === 'Federative Structure')
            <dl class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-x-md gap-y-[18px]">
                @foreach ([
                    ['People reached', isset($profile['beneficiaries']) ? Viz::compact($profile['beneficiaries']) : null, 'A year (Q518)'],
                    [$federative ? 'Member YMCAs' : 'Branches', $federative ? ($identity['member_organisations'] ?? null) : ($identity['branches'] ?? null), $identity['structure'] ?? 'Structure not given'],
                    ['Staff', $people['staff'], 'Paid staff (Q1008)'],
                    ['Volunteers', $people['volunteers'], 'Q1013'],
                    ['Board members', $people['board'], 'Current members'],
                    ['Women on the Board', $people['women'] !== null ? $people['women'].'%' : null, 'Of the current Board'],
                    ['Under 30 on the Board', $people['under_30'] !== null ? $people['under_30'].'%' : null, 'Of the current Board'],
                ] as [$name, $value, $note])
                    <div>
                        <dt class="{{ $label }}">{{ $name }}</dt>
                        <dd class="mt-0.5 text-[1.375rem] font-bold tabular-nums">{{ $value === null ? '—' : (is_string($value) ? $value : Viz::number($value)) }}</dd>
                        <dd class="{{ $vs }}">{{ $note }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-panel>
    @endif

    {{-- The cycle's journey, for those who follow it up (not the Board Chairperson). --}}
    @if ($journey !== [])
        @include('baselines._journey', ['assessment' => $lastDone, 'journey' => $journey, 'link' => true])
    @endif

    @if ($history->count() > 1)
        @include('movements._score-history')
    @endif

    @if ($profile)
        @include('movements._profile')
    @endif

    {{-- Stage 1 as its three approved documents, with their dates (27 Nov 2026): for everyone who sees the page. --}}
    <x-stage-documents :entries="$stageOne" id="documents" class="scroll-mt-lg" />

    {{-- Who leads the movement · its contacts at AAYMCA. --}}
    <div class="grid gap-md items-start lg:grid-cols-2">
        <x-panel title="People" subtitle="Who leads the movement">
            <ul class="flex flex-col">
                @foreach ([
                    ['Board Chairperson', $chair, 'May sign the ODP (optional)', 'No Board Chairperson yet. Signing the ODP is optional.'],
                    ['National General Secretary', $generalSecretary, 'Supplies the evidence', 'No National General Secretary yet, so AAYMCA staff add the evidence.'],
                ] as [$role, $person, $does, $none])
                    <li class="flex items-center gap-sm py-[10px] border-t border-[#f0eeea] first:border-0 first:pt-0">
                        @if ($person)
                            <x-avatar :user="$person" />
                            <span class="flex-1 min-w-0">
                                <span class="block text-[0.875rem] font-semibold truncate">{{ $person->name }}</span>
                                <span class="block text-[0.78125rem] text-on-surface-variant">{{ $role }} · {{ $does }}</span>
                            </span>
                        @else
                            <span class="flex-1 min-w-0">
                                <span class="block text-[0.78125rem] font-semibold text-on-surface-variant">{{ $role }}</span>
                                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $none }}</span>
                            </span>
                            <x-status-pill tone="warning">To appoint</x-status-pill>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-panel>

        <x-panel title="Contacts at AAYMCA" subtitle="The staff assessing the movement">
            @forelse ($assessors as $person)
                <div class="flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0">
                    <x-avatar :user="$person" class="!size-[38px]" />
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold">{{ $person->name }}</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant break-words">{{ $person->title ?? $person->role->label() }}</span>
                    </span>
                    <a href="mailto:{{ $person->email }}" title="{{ $person->email }}" aria-label="Email {{ $person->name }} ({{ $person->email }})"
                       class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] hover:bg-[#f2f1ee] hover:text-on-surface">
                        <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/></svg>
                    </a>
                </div>
            @empty
                <x-empty-state icon="support_agent">AAYMCA has not yet named who assesses the movement.</x-empty-state>
            @endforelse
        </x-panel>
    </div>

    @if ($d['rated'])
        <x-kpi-glossary :keys="['current_score', 'baseline', 'change_since_baseline', 'pace_needed', 'health_band', 'strongest_weakest', 'area_score', 'points_open', 'international_funding', 'restricted_funding', 'operating_margin', 'cash_reserve', 'policies_in_place', 'board_make_up']" />
    @endif

    {{-- AAYMCA staff only: running the movement's assessments, each part behind its own permission. --}}
    @if ($manage)
        <section id="manage" aria-labelledby="manage-title" class="flex flex-col gap-md scroll-mt-lg border-t border-[#e2dfd8] pt-lg">
            <div class="flex flex-col gap-[2px]">
                <h2 id="manage-title" class="text-[1.25rem] font-bold text-on-surface">Manage</h2>
                <p class="text-[0.875rem] text-on-surface-variant">AAYMCA only · the movement does not see this section.</p>
            </div>

            <div class="grid gap-md sm:grid-cols-2">
                {{-- No band-based date (decided 12 Nov 2026): the next assessment starts once the cycle is closed. --}}
                @php([$nextText, $nextTone] = $stage->nextAssessment($status->next_assessment_on))
                <x-kpi label="Next assessment" :value="$nextText">
                    <x-slot:badge>
                        @if ($paused)
                            <x-status-pill tone="warning">Paused</x-status-pill>
                        @elseif ($nextTone === 'critical')
                            <x-status-pill tone="critical">Past its planned date</x-status-pill>
                        @endif
                    </x-slot:badge>
                    @if ($paused)
                        Paused until {{ $movement->follow_up_paused_until->asDay() }}
                    @elseif ($status->latest_assessment_id)
                        Once the cycle is closed, started by its assessors or AAYMCA
                    @else
                        {{ $movement->planned_assessment_label ? 'Planned '.$movement->planned_assessment_label : 'Not planned yet' }}
                    @endif
                </x-kpi>

                <x-kpi label="Health Assessment Evidence Library" :value="$evidenceCount" :unit="' '.\Illuminate\Support\Str::plural('document', $evidenceCount)">
                    <a href="{{ route('evidence.index', $movement) }}" class="inline-flex items-center gap-[4px] font-semibold text-on-surface hover:underline">
                        Open the library
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                </x-kpi>
            </div>

            <div class="grid gap-md lg:grid-cols-[1.55fr_1fr] items-start">
                <div class="flex flex-col gap-md min-w-0">
                    {{-- How its ODP is carried out --}}
                    @if ($followUpProgress && $followUpProgress['summary']['total'] > 0)
                        @php($s = $followUpProgress['summary'])
                        <x-panel title="ODP follow-up" :subtitle="'The '.$lastDone->period_label.' ODP · as set at the last follow-up'">
                            <x-slot:actions><x-row-action :href="route('follow-up.show', $lastDone)">Open the follow-up</x-row-action></x-slot:actions>
                            <div class="flex flex-col gap-lg">
                                <div class="flex items-center gap-md">
                                    <x-progress-ring :value="$s['done']" :max="max(1, $s['total'])" :size="84" />
                                    <div class="flex flex-col gap-sm">
                                        <p class="text-[0.9375rem] font-semibold">{{ $s['done'] }} of {{ $s['total'] }} tasks done</p>
                                        <div class="flex flex-wrap gap-xs">
                                            @if ($s['in_progress'] > 0)<x-status-pill tone="warning">{{ $s['in_progress'] }} in progress</x-status-pill>@endif
                                            @if ($s['blocked'] > 0)<x-status-pill tone="critical">{{ $s['blocked'] }} blocked</x-status-pill>@else<x-status-pill tone="good">Nothing blocked</x-status-pill>@endif
                                        </div>
                                    </div>
                                </div>
                                @if (count($followUpProgress['priorities']) > 1)
                                    <x-priority-bars :priorities="$followUpProgress['priorities']" />
                                @endif
                            </div>
                        </x-panel>
                    @endif

                    {{-- Its assessments, one row each --}}
                    <x-panel flush title="Assessments" id="assessments">
                        @if ($underWay)
                            {{-- One assessment at a time: no "Start new assessment" while this one runs. --}}
                            <x-slot:actions>
                                <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                                    <x-status-pill tone="info">Assessment started</x-status-pill>
                                    @can('view', $underWay)
                                        <a href="{{ route('assessments.show', $underWay) }}" class="font-semibold hover:underline">{{ $underWay->period_label }}</a>
                                    @else
                                        <span class="font-semibold">{{ $underWay->period_label }}</span>
                                    @endcan
                                </span>
                            </x-slot:actions>
                        @elseif ($lastDone && $followingUp)
                            {{-- Stage 1 done: the assessment is closed and its follow-ups are under way until the cycle is closed. --}}
                            <x-slot:actions>
                                <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                                    <x-status-pill tone="good">Stage 1 done</x-status-pill>
                                    <x-status-pill tone="warning">Follow-ups ongoing</x-status-pill>
                                    @can('viewFollowUp', $lastDone)
                                        <x-row-action primary :href="route('follow-up.show', $lastDone)">ODP follow-up</x-row-action>
                                    @endcan
                                </span>
                            </x-slot:actions>
                        @endif

                        {{-- Starting one: the first, or the next once the previous cycle is closed (see MovementPolicy::openAssessment). --}}
                        @if ($canOpen && ! $underWay)
                            <div id="start" class="px-lg py-md border-b border-[#efede8] bg-[#fafaf8]">
                                <p class="text-[0.875rem] font-semibold">Start the assessment</p>
                                @include('movements._start-form')
                            </div>
                        @endif

                        @if ($assessments->isEmpty())
                            <div class="px-lg py-lg"><x-empty-state icon="fact_check">No assessments yet.</x-empty-state></div>
                        @else
                            <ul class="flex flex-col">
                                @foreach ($assessments as $assessment)
                                    @php($parts = $assessment->artefacts->keyBy(fn ($a) => $a->kind->value))
                                    <li class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
                                        <div class="flex-[999_1_9rem] min-w-0 flex flex-col gap-[2px]">
                                            <a href="{{ route('assessments.show', $assessment) }}" class="text-[0.9375rem] font-semibold hover:underline">{{ $assessment->period_label }}</a>
                                            @if ($assessment->score)
                                                <span class="text-[0.8125rem] text-on-surface-variant">{{ Viz::pct($assessment->score->pct) }} · {{ $assessment->score->band->label }}</span>
                                            @endif
                                        </div>
                                        <dl class="flex flex-wrap items-center gap-x-md gap-y-xs">
                                            @foreach (['form', 'report', 'odp'] as $kind)
                                                @php($display = \App\Enums\ArtefactState::display($parts[$kind]->status->effective_state))
                                                <div class="flex items-center gap-[6px]">
                                                    <dt class="text-[0.8125rem] text-on-surface-variant">{{ __('oha.artefact.'.$kind) }}</dt>
                                                    <dd><x-status-pill :tone="$display['tone']">{{ $display['label'] }}</x-status-pill></dd>
                                                    @if ($kind === 'odp')
                                                        <dd><x-validation-chip :status="$parts[$kind]->status" /></dd>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </dl>
                                        {{-- A closed assessment: its follow-up (tasks, meetings, the overall review and carry-over). --}}
                                        @if ($assessment->workItem === null && $me->can('viewFollowUp', $assessment))
                                            <x-row-action :href="route('follow-up.show', $assessment)">Follow-up</x-row-action>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-panel>
                </div>

                <div class="flex flex-col gap-md min-w-0">
                    @if ($cycle)
                        <x-panel title="Assessment cycle" :subtitle="$cycleOf->period_label.' assessment'">
                            <x-cycle-timeline :steps="$cycle" />
                        </x-panel>
                    @endif

                    @include('movements._assessors')
                </div>
            </div>
        </section>
    @endif
</x-layouts.app>
