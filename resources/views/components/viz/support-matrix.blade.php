{{--
    Who needs support (decided 15 Nov 2026, rebuilt 23 Nov 2026 as approved on the mockup):
    each movement placed by its current score (across) and its change since the baseline (up),
    split where the Strong band starts and at no change. A movement not followed up yet has no
    change to show: it sits in the lane beneath, placed by its score. Hovering, focusing or
    tapping a dot opens its card (band, score, change, its scores over the cycle, its weakest
    area, a link to it); a tap keeps it open, Escape closes it. The key beneath, grouped as the
    chart is, lights the dot of the movement under the pointer.
    Expects $matrix from App\Queries\DashboardAnalytics::region()['matrix'].
--}}
@use('App\Support\Viz')
@use('App\Models\Baseline')
@props(['matrix'])
@php
    $plot = 420;
    $laneTop = $plot + 16;
    $height = $laneTop + 56;
    $points = collect($matrix['points']);
    $split = $matrix['x']['split_at'];
    $zero = $matrix['y']['zero_at'];
    $yAt = fn (float $t) => round(($matrix['y']['to'] - $t) / ($matrix['y']['to'] - $matrix['y']['from']) * $plot, 1);
    $xAt = fn (float $p) => round(($p - $matrix['x']['from']) / (100 - $matrix['x']['from']) * 100, 2);
    $groups = [
        0 => ['Needs support', 'text-serious-ink'],
        1 => ['Improving, not yet strong', 'text-info-ink'],
        2 => ['Strong, not improving', 'text-[#4a4950]'],
        3 => ['Strong and improving', 'text-good-ink'],
        4 => ['No follow-up yet', 'text-on-surface-variant'],
    ];
    $quad = 'absolute text-[0.6875rem] font-semibold uppercase tracking-[0.06em] pointer-events-none';
    $short = fn ($m) => str_replace(' YMCA', '', $m->name);
    // The card's small line of the scores over the cycle: 232 × 44, the bands from 40% up.
    $spark = function (array $steps) {
        $x = fn (int $i) => 8 + $i * 72;
        $y = fn (float $p) => round(38 - (max(40, $p) - 40) / 60 * 32, 1);

        return ['points' => collect($steps)->map(fn ($p, $i) => $x($i).','.$y((float) $p))->join(' '), 'dots' => collect($steps)->map(fn ($p, $i) => ['x' => $x($i), 'y' => $y((float) $p)])->all(), 'strong' => $y(70)];
    };
@endphp
<div {{ $attributes->class('flex flex-col gap-lg') }} x-data="{ hover: null, pinned: null }" x-on:keydown.escape="pinned = null; hover = null">
    <div class="flex gap-sm">
        <div class="flex-none w-5 flex items-center justify-center" aria-hidden="true">
            <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant whitespace-nowrap [writing-mode:vertical-rl] rotate-180">Points since baseline</span>
        </div>
        <div class="flex-1 min-w-0 pl-[2.25rem]">
            <div class="relative" style="height: {{ $height }}px" x-on:mouseleave="hover = null" role="group"
                 aria-label="Each movement by its current score and its change since baseline. The key below lists every movement.">
                {{-- The four groups --}}
                <span class="absolute left-0 top-0 rounded-tl-xl bg-info-wash" style="width: {{ $split }}%; height: {{ $zero / 100 * $plot }}px" aria-hidden="true"></span>
                <span class="absolute right-0 top-0 rounded-tr-xl bg-good-wash" style="left: {{ $split }}%; height: {{ $zero / 100 * $plot }}px" aria-hidden="true"></span>
                <span class="absolute left-0 rounded-bl-xl bg-serious-wash" style="width: {{ $split }}%; top: {{ $zero / 100 * $plot }}px; height: {{ $plot - $zero / 100 * $plot }}px" aria-hidden="true"></span>
                <span class="absolute right-0 rounded-br-xl bg-neutral-wash" style="left: {{ $split }}%; top: {{ $zero / 100 * $plot }}px; height: {{ $plot - $zero / 100 * $plot }}px" aria-hidden="true"></span>

                {{-- Grid and scales --}}
                @foreach ($matrix['y']['ticks'] as $t)
                    <span @class(['absolute inset-x-0 border-t', 'border-outline border-dashed' => $t == 0, 'border-[#1c1b1f]/[0.06]' => $t != 0]) style="top: {{ $yAt($t) }}px" aria-hidden="true"></span>
                    <span class="absolute -left-[2.25rem] -translate-y-1/2 text-[0.75rem] text-on-surface-variant tabular-nums" style="top: {{ $yAt($t) }}px" aria-hidden="true">{{ $t > 0 ? '+' : '' }}{{ $t }}</span>
                @endforeach
                @foreach ($matrix['x']['ticks'] as $p)
                    <span @class(['absolute top-0 border-l', 'border-outline border-dashed' => $p == $matrix['x']['split'], 'border-[#1c1b1f]/[0.06]' => $p != $matrix['x']['split']]) style="left: {{ $xAt($p) }}%; height: {{ $plot }}px" aria-hidden="true"></span>
                    <span @class(['absolute -translate-x-1/2 text-[0.75rem] text-on-surface-variant tabular-nums whitespace-nowrap', 'font-semibold text-on-surface' => $p == $matrix['x']['split']]) style="left: {{ $xAt($p) }}%; top: {{ $height + 6 }}px" aria-hidden="true">{{ $p }}%{{ $p == $matrix['x']['split'] ? ' · Strong' : '' }}</span>
                @endforeach

                <span class="{{ $quad }} left-sm top-sm text-info-ink" aria-hidden="true">Improving, not yet strong</span>
                <span class="{{ $quad }} right-sm top-sm text-good-ink text-right" aria-hidden="true">Strong and improving</span>
                <span class="{{ $quad }} left-sm text-serious-ink" style="top: {{ $plot - 24 }}px" aria-hidden="true">Needs support</span>
                <span class="{{ $quad }} right-sm text-[#4a4950] text-right" style="top: {{ $plot - 24 }}px" aria-hidden="true">Strong, not improving</span>

                {{-- The lane for those not followed up yet --}}
                <span class="absolute inset-x-0 rounded-xl border border-dashed border-[#dcd8d0] bg-[#faf9f6]" style="top: {{ $laneTop }}px; height: 48px" aria-hidden="true"></span>
                <span class="{{ $quad }} right-sm text-on-surface-variant" style="top: {{ $laneTop + 16 }}px" aria-hidden="true">No follow-up yet</span>

                {{-- The movements --}}
                @foreach ($points as $p)
                    @php($top = $p['top'] === null ? $laneTop + 24 : round($p['top'] / 100 * $plot, 1))
                    <button type="button"
                            @class(['absolute -translate-x-1/2 -translate-y-1/2 size-[28px] rounded-full border-2 border-on-surface grid place-items-center text-[0.75rem] font-bold tabular-nums transition-transform focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info-ink',
                                'bg-on-surface text-white' => $p['change'] !== null, 'bg-surface-container-lowest text-on-surface' => $p['change'] === null])
                            style="left: {{ $p['left'] }}%; top: {{ $top }}px"
                            x-bind:class="(pinned ?? hover) === {{ $p['n'] }} ? 'z-10 scale-125 shadow-[0_0_0_6px_rgba(200,16,46,0.18)]' : 'z-[1]'"
                            x-on:mouseenter="hover = {{ $p['n'] }}" x-on:focus="hover = {{ $p['n'] }}" x-on:blur="hover = null"
                            x-on:click="pinned = pinned === {{ $p['n'] }} ? null : {{ $p['n'] }}"
                            x-bind:aria-expanded="(pinned === {{ $p['n'] }}).toString()" aria-expanded="false"
                            aria-label="{{ $p['movement']->name }}: {{ Viz::pct($p['pct']) }}, {{ $p['change'] === null ? 'no follow-up yet' : Viz::signed($p['change']).' points since baseline' }}">{{ $p['n'] }}</button>
                @endforeach

                {{-- Each movement's card --}}
                @foreach ($points as $p)
                    @php($top = $p['top'] === null ? $laneTop + 24 : round($p['top'] / 100 * $plot, 1))
                    @php($line = $spark($p['steps']))
                    <div x-show="(pinned ?? hover) === {{ $p['n'] }}" x-cloak x-transition.opacity role="dialog" aria-label="{{ $p['movement']->name }}"
                         class="absolute z-20 w-[16.5rem] max-w-[calc(100%-1rem)] flex flex-col gap-sm rounded-xl border border-[#e7e5e0] bg-surface-container-lowest px-[16px] py-[14px] shadow-[0_12px_32px_rgba(20,20,20,0.16)]"
                         style="{{ $p['left'] > 58 ? 'right: calc('.(100 - $p['left']).'% + 24px)' : 'left: calc('.$p['left'].'% + 24px)' }}; {{ $top > 260 ? 'bottom: '.max(0, $height - $top - 30).'px' : 'top: '.max(0, $top - 30).'px' }}">
                        <div class="flex items-start justify-between gap-sm">
                            <div class="min-w-0">
                                <p class="text-[0.9375rem] font-bold">{{ $p['movement']->name }}</p>
                                <p class="text-[0.8125rem] text-on-surface-variant">{{ $p['movement']->zone->name }}</p>
                            </div>
                            @if ($p['band'])
                                <x-status-pill :tone="\App\Support\Bands::tone($p['band']->code)">{{ $p['band']->label }}</x-status-pill>
                            @endif
                        </div>
                        <dl class="grid grid-cols-2 gap-sm">
                            <div><dt class="text-[0.6875rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Current score</dt><dd class="text-[1.25rem] font-bold tabular-nums">{{ Viz::pct($p['pct']) }}</dd></div>
                            <div><dt class="text-[0.6875rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Since baseline</dt><dd @class(['text-[1.25rem] font-bold tabular-nums', 'text-good-ink' => ($p['change'] ?? 0) > 0, 'text-critical-ink' => ($p['change'] ?? 0) < 0])>{{ $p['change'] === null ? '—' : Viz::signed($p['change']) }}</dd></div>
                        </dl>
                        @if (count($p['steps']) > 1)
                            <svg viewBox="0 0 232 44" class="w-full h-[44px]" role="img" aria-label="{{ collect($p['steps'])->map(fn ($s, $i) => Baseline::name($i + 1).' '.Viz::pct((float) $s))->join(', ') }}">
                                <line x1="8" x2="224" y1="{{ $line['strong'] }}" y2="{{ $line['strong'] }}" stroke="#bfdcc9" stroke-dasharray="3 3" />
                                <polyline points="{{ $line['points'] }}" fill="none" stroke="var(--color-on-surface)" stroke-width="2" stroke-linejoin="round" />
                                @foreach ($line['dots'] as $dot)
                                    <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="3.5" fill="var(--color-on-surface)" />
                                @endforeach
                            </svg>
                            <p class="text-[0.78125rem] text-on-surface-variant tabular-nums">{{ collect($p['steps'])->map(fn ($s) => Viz::figure((float) $s, 1))->join(' → ') }}</p>
                        @else
                            <p class="text-[0.8125rem] text-on-surface-variant">No follow-up score yet: the baseline is its only score.</p>
                        @endif
                        @if ($p['weakest'])
                            <p class="text-[0.8125rem] text-on-surface-variant">Weakest area: <span class="font-semibold text-on-surface">{{ $p['weakest']['name'] }} {{ Viz::pct($p['weakest']['pct'], 0) }}</span></p>
                        @endif
                        <a href="{{ route('movements.show', $p['movement']) }}" class="text-[0.8125rem] font-semibold text-on-surface hover:underline">Open movement →</a>
                    </div>
                @endforeach
            </div>
            <p class="mt-[2rem] text-center text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant" aria-hidden="true">Current score</p>
        </div>
    </div>

    {{-- The key: every movement, grouped as the chart is. --}}
    <div class="grid gap-md sm:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(13rem,1fr))] border-t border-[#f0eeea] pt-md">
        @foreach ($groups as $g => [$name, $ink])
            @php($items = $points->where('group', $g))
            @continue($items->isEmpty())
            <div class="flex flex-col gap-[2px] min-w-0">
                <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] {{ $ink }} mb-xs">{{ $name }} · {{ $items->count() }}</p>
                @foreach ($items as $p)
                    <div class="flex items-center gap-sm rounded-lg px-[6px] py-[5px] text-[0.8125rem]"
                         x-bind:class="(pinned ?? hover) === {{ $p['n'] }} ? 'bg-[#f4f2ee]' : ''"
                         x-on:mouseenter="hover = {{ $p['n'] }}" x-on:mouseleave="hover = null">
                        <span @class(['flex-none size-[20px] rounded-full border-2 border-on-surface grid place-items-center text-[0.6875rem] font-bold tabular-nums',
                            'bg-on-surface text-white' => $p['change'] !== null, 'bg-surface-container-lowest' => $p['change'] === null]) aria-hidden="true">{{ $p['n'] }}</span>
                        <a href="{{ route('movements.show', $p['movement']) }}" class="flex-1 min-w-0 truncate font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ $short($p['movement']) }}</a>
                        <span class="tabular-nums whitespace-nowrap text-on-surface-variant">{{ Viz::pct($p['pct']) }} · <span @class(['font-semibold', 'text-good-ink' => ($p['change'] ?? 0) > 0, 'text-critical-ink' => ($p['change'] ?? 0) < 0])>{{ $p['change'] === null ? 'not yet' : Viz::signed($p['change']) }}</span></span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
