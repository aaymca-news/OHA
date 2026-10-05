{{--
    A 100% stacked bar: part-to-whole. Segments keep their slot colour in a fixed order
    (never by rank), are separated by a 2px surface gap, and carry their value in the
    legend below and on hover, so nothing depends on colour alone.
    Expects $segments = list of ['label' => string, 'value' => float] and optionally $caption.
--}}
@props(['segments', 'caption' => null, 'legend' => true, 'thin' => false])
@php
    $total = max(0.0001, array_sum(array_column($segments, 'value')));
@endphp
<div {{ $attributes->class('flex flex-col gap-xs') }}>
    @if ($caption)
        <p class="text-[0.8125rem] font-semibold">{{ $caption }}</p>
    @endif
    <div class="flex gap-[2px] {{ $thin ? 'h-3' : 'h-5' }} rounded overflow-hidden" role="img"
         aria-label="{{ collect($segments)->map(fn ($s) => $s['label'].' '.round($s['value'] * 100 / $total).'%')->join(', ') }}">
        @foreach ($segments as $i => $segment)
            @continue($segment['value'] <= 0)
            <span class="{{ \App\Support\Viz::SLOTS[$i] ?? 'bg-outline' }} h-full" style="width: {{ $segment['value'] * 100 / $total }}%"
                  title="{{ $segment['label'] }}: {{ round($segment['value'] * 100 / $total, 1) }}%"></span>
        @endforeach
    </div>
    @if ($legend)
        <ul class="flex flex-wrap gap-x-md gap-y-xs text-[0.8125rem]">
            @foreach ($segments as $i => $segment)
                <li class="flex items-center gap-xs">
                    <span class="w-2.5 h-2.5 rounded-sm {{ \App\Support\Viz::SLOTS[$i] ?? 'bg-outline' }}" aria-hidden="true"></span>
                    <span class="text-on-surface-variant">{{ $segment['label'] }}</span>
                    <span class="font-semibold tabular-nums">{{ round($segment['value'] * 100 / $total) }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
