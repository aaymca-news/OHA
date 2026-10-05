{{-- A score ring: one ratio against its maximum. The band colour is always paired with the % and the band label. --}}
@props(['pct' => null, 'band' => null, 'size' => 64])
@php
    $r = 15.915;
    $value = $pct !== null ? max(0, min(100, (float) $pct)) : 0;
@endphp
<div {{ $attributes->class('relative shrink-0') }} style="width: {{ $size }}px; height: {{ $size }}px"
     role="img" aria-label="{{ $pct !== null ? number_format((float) $pct, 1).'%, '.($band?->label ?? '') : 'Not yet assessed' }}">
    <svg viewBox="0 0 36 36" class="w-full h-full -rotate-90" aria-hidden="true">
        <circle cx="18" cy="18" r="{{ $r }}" fill="none" stroke-width="3.2" class="stroke-surface-container-high"
                @if ($pct === null) stroke-dasharray="2 2" @endif />
        @if ($pct !== null && $value > 0)
            <circle cx="18" cy="18" r="{{ $r }}" fill="none" stroke-width="3.2" stroke-linecap="round" stroke="currentColor"
                    class="{{ \App\Support\Viz::bandStroke($band?->code) }}" stroke-dasharray="{{ $value }} {{ 100 - $value }}" />
        @endif
    </svg>
    <span class="absolute inset-0 flex items-center justify-center text-[0.8125rem] font-bold text-primary">
        {{ $pct !== null ? round((float) $pct).'%' : '—' }}
    </span>
</div>
