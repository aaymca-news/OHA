{{--
    Where a score sits among the health bands: the bands as one soft scale from 0 to 100,
    a marker at the score, and the band it falls in named beneath the marker.
--}}
@use('App\Support\Bands')
@props(['pct'])
@php
    $scale = Bands::scale();
    $pct = max(0, min(100, (float) $pct));
    $mine = collect($scale)->first(fn ($b) => $pct >= $b['from'] && ($pct < $b['to'] || $b['to'] >= 100));
    $wash = ['critical' => 'bg-[#ecbcb7]', 'atrisk' => 'bg-[#f3cdb9]', 'developing' => 'bg-[#f5dfa8]', 'strong' => 'bg-[#bfdcc9]', 'excellent' => 'bg-[#a3d0b3]'];
    // The label sits under the marker, kept inside the scale at either end.
    $align = $pct < 15 ? 'left-0' : ($pct > 85 ? 'right-0' : '-translate-x-1/2');
@endphp
<div {{ $attributes->class('flex flex-col gap-[6px]') }} role="img"
     aria-label="{{ \App\Support\Viz::pct($pct) }}{{ $mine ? ', in the '.$mine['label'].' band ('.(int) $mine['from'].'–'.(int) $mine['to'].'%)' : '' }}">
    <div class="relative flex gap-[3px] h-2">
        @foreach ($scale as $b)
            <span class="h-full {{ $wash[$b['code']] ?? 'bg-surface-container' }} first:rounded-l-full last:rounded-r-full" style="flex: {{ $b['to'] - $b['from'] }} 1 0" title="{{ $b['label'] }}: {{ (int) $b['from'] }}–{{ (int) $b['to'] }}%"></span>
        @endforeach
        <span class="absolute -top-[5px] w-1 h-[18px] -ml-0.5 rounded-sm bg-on-surface shadow-[0_0_0_2px_#fff]" style="left: {{ $pct }}%"></span>
    </div>
    <div class="relative h-4 text-[0.6875rem] text-on-surface-variant" aria-hidden="true">
        <span class="absolute left-0">0</span>
        @if ($mine)
            <span class="absolute whitespace-nowrap font-semibold text-on-surface bg-surface-container-lowest px-1 {{ $align }}" style="{{ $pct >= 15 && $pct <= 85 ? 'left: '.$pct.'%' : '' }}">{{ $mine['label'] }} · {{ (int) $mine['from'] }}–{{ (int) $mine['to'] }}%</span>
        @endif
        <span class="absolute right-0">100</span>
    </div>
</div>
