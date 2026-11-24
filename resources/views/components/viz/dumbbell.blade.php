{{--
    From baseline to now (decided 23 Nov 2026): one row per movement followed up, its
    baseline (hollow) and its current score (filled) on one scale, joined by a line in green
    (up) or red (down), biggest gain first. Dashed lines mark where the bands begin.
      rows  list of ['movement' => Movement, 'from' => float, 'to' => float, 'change' => float]
      from  where the scale starts (%)
--}}
@use('App\Support\Viz')
@props(['rows', 'from' => 40])
@php
    $x = fn (float $p) => round((max($from, $p) - $from) / (100 - $from) * 100, 2);
    $edges = collect(\App\Support\Bands::scale())->pluck('from')->filter(fn ($p) => $p > $from)->values();
@endphp
<div {{ $attributes->class('grid grid-cols-[minmax(5rem,8rem)_1fr_3.5rem] gap-x-[12px] gap-y-[10px] items-center') }}>
    <span aria-hidden="true"></span>
    <div class="relative h-4 text-[0.75rem] text-on-surface-variant tabular-nums" aria-hidden="true">
        @foreach ($edges as $edge)
            <span class="absolute -translate-x-1/2" style="left: {{ $x($edge) }}%">{{ Viz::figure($edge) }}%</span>
        @endforeach
    </div>
    <span aria-hidden="true"></span>

    @foreach ($rows as $row)
        @php([$a, $b] = [$x($row['from']), $x($row['to'])])
        <a href="{{ route('movements.show', $row['movement']) }}" class="truncate text-[0.8125rem] font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ str_replace(' YMCA', '', $row['movement']->name) }}</a>
        <div class="relative h-[22px]" role="img" aria-label="{{ $row['movement']->name }}: {{ Viz::pct($row['from']) }} at baseline, {{ Viz::pct($row['to']) }} now">
            @foreach ($edges as $edge)
                <span class="absolute inset-y-0 border-l border-dashed border-[#e4e1db]" style="left: {{ $x($edge) }}%" aria-hidden="true"></span>
            @endforeach
            <span @class(['absolute top-[10px] h-[3px] rounded-full', 'bg-good-ink' => $row['change'] > 0, 'bg-critical-ink' => $row['change'] < 0, 'bg-outline' => $row['change'] == 0])
                  style="left: {{ min($a, $b) }}%; width: {{ abs($b - $a) }}%"></span>
            <span class="absolute top-[11px] size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[#6b6a70] bg-surface-container-lowest" style="left: {{ $a }}%" title="Baseline {{ Viz::pct($row['from']) }}"></span>
            <span @class(['absolute top-[11px] size-[14px] -translate-x-1/2 -translate-y-1/2 rounded-full', 'bg-good-ink' => $row['change'] > 0, 'bg-critical-ink' => $row['change'] < 0, 'bg-outline' => $row['change'] == 0])
                  style="left: {{ $b }}%" title="Now {{ Viz::pct($row['to']) }}"></span>
        </div>
        <span @class(['text-right text-[0.8125rem] font-bold tabular-nums', 'text-good-ink' => $row['change'] > 0, 'text-critical-ink' => $row['change'] < 0])>{{ Viz::signed($row['change']) }}</span>
    @endforeach
</div>
<p class="mt-md flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant">
    <span class="inline-flex items-center gap-xs"><span class="size-3 rounded-full border-2 border-[#6b6a70]" aria-hidden="true"></span>Baseline</span>
    <span class="inline-flex items-center gap-xs"><span class="size-3 rounded-full bg-good-ink" aria-hidden="true"></span>Now</span>
    <span>Scale {{ $from }}–100%; dashed lines: where the bands begin</span>
</p>
