{{--
    Who needs support (decided 15 Nov 2026): each rated movement placed by its current score
    (across) and its change since the baseline (up), split where the Strong band starts and
    at no change, so four groups read at a glance. A movement not followed up yet is drawn
    hollow, on the no-change line. Each dot carries a number; the key beneath names every
    movement with its figures, and is the chart's table.
    Expects $matrix from App\Queries\DashboardAnalytics::region()['matrix'].
--}}
@use('App\Support\Viz')
@props(['matrix'])
@php
    $points = collect($matrix['points'])->sortByDesc('pct')->values()->map(fn ($p, $i) => $p + ['n' => $i + 1]);
    $split = $matrix['x']['split_at'];
    $zero = $matrix['y']['zero_at'];
    $quad = 'absolute text-[0.6875rem] font-semibold uppercase tracking-[0.06em]';
    $tick = 'absolute text-[0.75rem] text-on-surface-variant tabular-nums whitespace-nowrap';
@endphp
<div {{ $attributes->class('flex flex-col gap-lg') }}>
    <div class="relative h-[300px] ml-[2.5rem] mb-[1.25rem]" role="img"
         aria-label="Each movement by its current score and its change since baseline. The key below lists every movement.">
        <span class="absolute left-0 top-0 rounded-tl-lg bg-info-wash" style="width: {{ $split }}%; height: {{ $zero }}%"></span>
        <span class="absolute top-0 right-0 rounded-tr-lg bg-good-wash" style="left: {{ $split }}%; height: {{ $zero }}%"></span>
        <span class="absolute left-0 bottom-0 rounded-bl-lg bg-serious-wash" style="width: {{ $split }}%; top: {{ $zero }}%"></span>
        <span class="absolute right-0 bottom-0 rounded-br-lg bg-neutral-wash" style="left: {{ $split }}%; top: {{ $zero }}%"></span>
        <span class="{{ $quad }} left-sm top-sm text-info-ink">Improving, not yet strong</span>
        <span class="{{ $quad }} right-sm top-sm text-good-ink text-right">Strong and improving</span>
        <span class="{{ $quad }} left-sm bottom-xs text-serious-ink">Needs support</span>
        <span class="{{ $quad }} right-sm bottom-xs text-neutral-ink text-right">Strong, no change yet</span>
        <span class="absolute inset-y-0 border-l border-dashed border-outline" style="left: {{ $split }}%" aria-hidden="true"></span>
        <span class="absolute inset-x-0 border-t border-dashed border-outline" style="top: {{ $zero }}%" aria-hidden="true"></span>

        @foreach ($points as $p)
            <span title="{{ $p['movement']->name }}: {{ Viz::pct($p['pct']) }}, {{ $p['change'] === null ? 'not followed up yet' : Viz::signed($p['change']).' points since baseline' }}"
                  @class(['absolute -translate-x-1/2 -translate-y-1/2 size-[26px] rounded-full border-2 border-on-surface grid place-items-center text-[0.75rem] font-bold tabular-nums',
                      'bg-on-surface text-white' => $p['change'] !== null, 'bg-surface-container-lowest text-on-surface' => $p['change'] === null])
                  style="left: {{ $p['left'] }}%; top: {{ $p['top'] }}%" aria-hidden="true">{{ $p['n'] }}</span>
        @endforeach

        <span class="{{ $tick }} left-0 -bottom-[1.4rem]" aria-hidden="true">{{ Viz::figure($matrix['x']['from']) }}%</span>
        <span class="{{ $tick }} -translate-x-1/2 -bottom-[1.4rem]" style="left: {{ $split }}%" aria-hidden="true">{{ Viz::figure($matrix['x']['split']) }}% · Strong</span>
        <span class="{{ $tick }} right-0 -bottom-[1.4rem]" aria-hidden="true">100%</span>
        <span class="{{ $tick }} -left-[2.5rem] top-0" aria-hidden="true">+{{ Viz::figure($matrix['y']['to']) }}</span>
        <span class="{{ $tick }} -left-[1.25rem] -translate-y-1/2" style="top: {{ $zero }}%" aria-hidden="true">0</span>
    </div>

    {{-- The key: every movement, best score first. --}}
    <ol class="grid gap-x-lg gap-y-[6px] sm:grid-cols-2 xl:grid-cols-3 text-[0.8125rem]">
        @foreach ($points as $p)
            <li class="flex items-center gap-sm min-w-0">
                <span @class(['flex-none size-[20px] rounded-full border-2 border-on-surface grid place-items-center text-[0.6875rem] font-bold tabular-nums',
                    'bg-on-surface text-white' => $p['change'] !== null, 'bg-surface-container-lowest' => $p['change'] === null]) aria-hidden="true">{{ $p['n'] }}</span>
                <a href="{{ route('movements.show', $p['movement']) }}" class="truncate font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ str_replace(' YMCA', '', $p['movement']->name) }}</a>
                <span class="ml-auto tabular-nums whitespace-nowrap text-on-surface-variant">{{ Viz::pct($p['pct']) }} · <span @class(['font-semibold', 'text-good-ink' => ($p['change'] ?? 0) > 0, 'text-critical-ink' => ($p['change'] ?? 0) < 0])>{{ $p['change'] === null ? 'no follow-up yet' : Viz::signed($p['change']) }}</span></span>
            </li>
        @endforeach
    </ol>
</div>
