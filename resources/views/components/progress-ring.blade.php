{{--
    A progress ring: `value` of `max` done, the percentage in the middle (design standard
    of 10 Nov 2026). `size` in px.
--}}
@props(['value' => 0, 'max' => 0, 'size' => 88])
@php
    $r = 34;
    $circ = 2 * M_PI * $r;
    $share = $max > 0 ? max(0, min(1, $value / $max)) : 0;
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 88 88" role="img" aria-label="{{ $value }} of {{ $max }} done" {{ $attributes->class('flex-none') }}>
    <circle cx="44" cy="44" r="{{ $r }}" fill="none" stroke="#ecebe7" stroke-width="10"></circle>
    @if ($share > 0)
        <circle cx="44" cy="44" r="{{ $r }}" fill="none" stroke="var(--color-band-strong)" stroke-width="10" stroke-linecap="round"
                stroke-dasharray="{{ round($circ * $share, 1) }} {{ round($circ, 1) }}" transform="rotate(-90 44 44)"></circle>
    @endif
    <text x="44" y="50" text-anchor="middle" font-size="18" font-weight="700" fill="currentColor">{{ round($share * 100) }}%</text>
</svg>
