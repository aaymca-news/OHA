{{-- A horizontal bar with its value written beside it, so it reads without colour or JavaScript. --}}
@props(['value', 'max' => 100, 'label' => null, 'tone' => 'primary', 'wide' => false])
@php
    $width = $max > 0 ? max(0, min(100, $value / $max * 100)) : 0;
    $fills = ['primary' => 'bg-primary-container', 'good' => 'bg-band-strong', 'warning' => 'bg-band-developing', 'serious' => 'bg-band-atrisk', 'critical' => 'bg-band-critical', 'neutral' => 'bg-band-notstarted'];
@endphp
<div {{ $attributes->class('flex items-center gap-sm') }}>
    <div class="flex-1 h-2.5 rounded bg-surface-container overflow-hidden" role="img" aria-label="{{ $label ?? round($width).'%' }}">
        <div class="h-full rounded {{ $fills[$tone] ?? $fills['primary'] }}" style="width: {{ $width }}%"></div>
    </div>
    <span class="{{ $wide ? 'w-28' : 'w-20' }} text-right text-[0.8125rem] font-semibold tabular-nums">{{ $label ?? round($width).'%' }}</span>
</div>
