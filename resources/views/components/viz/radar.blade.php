{{--
    The health shape's frame (decided 23 Nov 2026): the scored areas as the corners of a
    regular polygon (an octagon for the 8 areas), rings where the bands begin (20, 50, 70 and
    90%) and at 100%, each corner with its area's icon and short name. The shapes themselves
    come in the slot, as SVG drawn with App\Support\Radar. Hovering or focusing a corner sets
    `axis` (its index) on the enclosing Alpine component, which picks out that area elsewhere.
      areas  list of ['short' => string, 'name' => string, 'icon' => Material Symbols name]
      label  what the shape shows, for screen readers
--}}
@use('App\Support\Radar')
@props(['areas', 'label'])
@php
    $n = count($areas);
@endphp
<div {{ $attributes->class('@container relative w-full max-w-[620px] mx-auto') }} style="aspect-ratio: {{ Radar::WIDTH }} / {{ Radar::HEIGHT }}">
    <svg viewBox="0 0 {{ Radar::WIDTH }} {{ Radar::HEIGHT }}" class="absolute inset-0 size-full" role="img" aria-label="{{ $label }}">
        {{-- The outer ring (filled) first, so the inner rings and the spokes show on it. --}}
        @foreach (array_reverse(Radar::RINGS) as $ring)
            <polygon points="{{ Radar::ring($n, $ring) }}" fill="{{ $ring === 100 ? '#fcfbf9' : 'none' }}" stroke="{{ $ring === 100 ? '#d9d6cf' : '#e3e0d9' }}" stroke-width="1" />
        @endforeach
        @for ($k = 0; $k < $n; $k++)
            @php([$x, $y] = Radar::point($k, $n, Radar::RADIUS))
            <line x1="{{ Radar::CX }}" y1="{{ Radar::CY }}" x2="{{ $x }}" y2="{{ $y }}" stroke="#ebe8e2" />
        @endfor
        {{ $slot }}
        {{-- The rings' percentages last, with a white halo, so the shapes never hide them. They sit along the spoke between the first two corners. --}}
        @foreach (Radar::RINGS as $ring)
            @continue($ring === 100)
            @php([$rx, $ry] = Radar::point(0, $n, $ring / 100 * Radar::RADIUS))
            <text x="{{ $rx + 6 }}" y="{{ $ry - 3 }}" font-size="11" font-weight="600" fill="var(--color-on-surface-variant)" stroke="#ffffff" stroke-width="3" paint-order="stroke" stroke-linejoin="round">{{ $ring }}%</text>
        @endforeach
        {{-- In a small drawing (a phone, or a half-width card) the area names give way to the corner icons, each naming its area, and the figures beside or beneath. --}}
        @foreach ($areas as $k => $area)
            @php($name = Radar::label($k, $n, 58))
            <text class="@max-[26rem]:hidden" x="{{ $name['x'] }}" y="{{ $name['y'] + ($name['top'] > 60 ? 10 : ($name['top'] < 40 ? -2 : 5)) }}" text-anchor="{{ $name['anchor'] }}" font-size="13.5" font-weight="600" fill="var(--color-on-surface)">{{ $area['short'] }}</text>
        @endforeach
    </svg>
    @foreach ($areas as $k => $area)
        @php($node = Radar::label($k, $n, 30))
        <button type="button" class="absolute -translate-x-1/2 -translate-y-1/2 size-[clamp(1.75rem,6.2%,2.5rem)] rounded-full border grid place-items-center transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info-ink"
                style="left: {{ $node['left'] }}%; top: {{ $node['top'] }}%"
                x-bind:class="axis === {{ $k }} ? 'bg-on-surface border-on-surface text-white' : 'bg-surface-container-lowest border-[#d9d6cf] text-on-surface'"
                x-on:mouseenter="axis = {{ $k }}" x-on:focus="axis = {{ $k }}" x-on:click="axis = {{ $k }}"
                aria-label="{{ $area['name'] }}" title="{{ $area['name'] }}">
            <span class="material-symbols-outlined text-[clamp(0.9rem,3.4vw,1.25rem)]" aria-hidden="true">{{ $area['icon'] }}</span>
        </button>
    @endforeach
</div>
