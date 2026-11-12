{{--
    A cycle's growth curve (decided 12 Nov 2026): the overall score at each baseline, from
    Baseline 1 (the closed assessment) to Baseline 4 (after the third follow-up). One series
    in ink, so no legend: the panel's title names it. The health bands' lower limits are
    solid hairlines, named at the right. Only the first and newest values are written on the
    chart; hovering (or focusing) a baseline shows its figures, and a table beside the chart
    carries every value. A baseline not reached yet is named beneath, with nothing plotted.
      points  list of ['label' => 'Baseline 2', 'pct' => float|null, 'note' => string], one per slot
      height  the plot's height in px
--}}
@props(['points', 'height' => 200])
@php
    $bands = \App\Models\HealthBand::query()->where('min_pct', '>', 1)->orderBy('min_pct')->get(['label', 'min_pct']);
    $slots = count($points);
    $x = fn (int $i) => $slots > 1 ? 6 + $i * (88 / ($slots - 1)) : 50;
    $reached = collect($points)->map(fn ($p, $i) => $p + ['i' => $i, 'x' => $x($i)])->filter(fn ($p) => $p['pct'] !== null)->values();
    $first = $reached->first();
    $last = $reached->last();
    $polyline = $reached->map(fn ($p) => round($p['x'], 2).','.round(100 - (float) $p['pct'], 2))->join(' ');
@endphp
<figure {{ $attributes->class('flex flex-col gap-sm') }} x-data="{ hover: null }">
    <div class="grid grid-cols-[2.25rem_1fr_6.5rem] gap-x-xs">
        {{-- The y axis: 0% and 100%, the bands carry the rest. --}}
        <div class="relative text-[0.75rem] text-on-surface-variant tabular-nums" style="height: {{ $height }}px" aria-hidden="true">
            <span class="absolute right-0 -top-[0.5em]">100%</span>
            <span class="absolute right-0 -bottom-[0.5em]">0%</span>
        </div>

        <div class="relative" style="height: {{ $height }}px">
            {{-- Hairlines: 0, each band's lower limit, 100. --}}
            <span class="absolute inset-x-0 bottom-0 border-t border-[#d9d7d1]" aria-hidden="true"></span>
            <span class="absolute inset-x-0 top-0 border-t border-[#efede8]" aria-hidden="true"></span>
            @foreach ($bands as $band)
                <span class="absolute inset-x-0 border-t border-[#efede8]" style="bottom: {{ (float) $band->min_pct }}%" aria-hidden="true"></span>
            @endforeach

            {{-- The crosshair on the baseline hovered. --}}
            @foreach ($points as $i => $point)
                <span x-show="hover === {{ $i }}" x-cloak class="absolute inset-y-0 border-l border-[#b8b6af]" style="left: {{ $x($i) }}%" aria-hidden="true"></span>
            @endforeach

            @if ($reached->count() > 1)
                <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <polyline points="{{ $polyline }}" fill="none" stroke="var(--color-on-surface)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
            @endif

            @foreach ($reached as $point)
                <span class="absolute size-[10px] -translate-x-1/2 translate-y-1/2 rounded-full bg-on-surface shadow-[0_0_0_2px_#ffffff]"
                      style="left: {{ $point['x'] }}%; bottom: {{ (float) $point['pct'] }}%" aria-hidden="true"></span>
                {{-- Direct labels: the first and the newest only. --}}
                @if ($point['i'] === $first['i'] || $point['i'] === $last['i'])
                    <span class="absolute -translate-x-1/2 text-[0.8125rem] font-semibold tabular-nums text-on-surface whitespace-nowrap"
                          style="left: {{ $point['x'] }}%; bottom: calc({{ (float) $point['pct'] }}% + 10px)" aria-hidden="true">{{ \App\Support\Viz::pct((float) $point['pct']) }}</span>
                @endif
            @endforeach

            {{-- Hit targets: the whole height of each baseline's column. --}}
            @foreach ($points as $i => $point)
                <span tabindex="0" role="img" aria-label="{{ $point['label'] }}: {{ $point['pct'] === null ? $point['note'] : \App\Support\Viz::pct((float) $point['pct']).', '.$point['note'] }}"
                      class="absolute inset-y-0 -translate-x-1/2 focus-visible:outline-2 focus-visible:outline-primary"
                      style="left: {{ $x($i) }}%; width: {{ 100 / max(1, $slots) }}%"
                      x-on:mouseenter="hover = {{ $i }}" x-on:mouseleave="hover = null" x-on:focus="hover = {{ $i }}" x-on:blur="hover = null"></span>
                <span x-show="hover === {{ $i }}" x-cloak role="tooltip"
                      class="pointer-events-none absolute z-10 -translate-x-1/2 rounded-lg bg-on-surface px-sm py-xs text-[0.78125rem] text-white shadow-md whitespace-nowrap"
                      style="left: {{ min(80, max(20, $x($i))) }}%; top: 4px">
                    <span class="font-semibold">{{ $point['label'] }}</span>
                    · {{ $point['pct'] === null ? $point['note'] : \App\Support\Viz::pct((float) $point['pct']).' · '.$point['note'] }}
                </span>
            @endforeach
        </div>

        {{-- The bands, named at the right. --}}
        <div class="relative text-[0.75rem] text-on-surface-variant" style="height: {{ $height }}px" aria-hidden="true">
            @foreach ($bands as $band)
                <span class="absolute left-0 translate-y-1/2 whitespace-nowrap" style="bottom: {{ (float) $band->min_pct }}%">{{ $band->label }} {{ \App\Support\Viz::figure((float) $band->min_pct) }}%</span>
            @endforeach
        </div>

        {{-- The baselines, beneath. --}}
        <span aria-hidden="true"></span>
        <div class="relative h-[2.5rem] text-[0.78125rem]" aria-hidden="true">
            @foreach ($points as $i => $point)
                <span class="absolute top-xs -translate-x-1/2 flex flex-col items-center text-center whitespace-nowrap" style="left: {{ $x($i) }}%">
                    <span @class(['font-semibold', 'text-on-surface-variant' => $point['pct'] === null])>{{ $point['label'] }}</span>
                    @if ($point['pct'] === null)<span class="text-on-surface-variant">not yet</span>@endif
                </span>
            @endforeach
        </div>
    </div>
    {{ $slot }}
</figure>
