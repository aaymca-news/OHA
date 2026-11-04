@props(['id', 'areas', 'why' => [], 'centre' => 'mark', 'label' => null])
{{--
    The nine areas of health as one ring: a segment per area, drawn one after another
    when the ring comes into view. A symbol of a whole, healthy movement; it never shows
    a real score. Pointing at (or tapping) a segment lifts it out of the ring and names
    its area in the centre. $centre: 'mark' (the Africa Alliance mark, which makes way
    for the area's icon, name and why it matters) or 'label' (the area's name only; the
    page's area cards light up with it).
--}}
<div {{ $attributes->merge(['class' => 'oha-ring-box relative']) }}>
    <svg class="oha-ring w-full h-auto overflow-visible" viewBox="0 0 400 400" aria-hidden="true" focusable="false" data-reveal @if ($centre === 'label') data-area-ring @endif>
        <circle class="ring-glow" cx="200" cy="200" r="150" fill="url(#ring-glow-{{ $id }})"/>
        <defs>
            <radialGradient id="ring-glow-{{ $id }}">
                <stop offset="0%" stop-color="#ef3340" stop-opacity="0.16"/>
                <stop offset="70%" stop-color="#ef3340" stop-opacity="0.04"/>
                <stop offset="100%" stop-color="#ef3340" stop-opacity="0"/>
            </radialGradient>
        </defs>
        <circle class="ring-orbit" cx="200" cy="200" r="190" fill="none" stroke="#b1b3b6" stroke-width="2" stroke-dasharray="1 11" stroke-linecap="round"/>
        <circle cx="200" cy="200" r="126" fill="none" stroke="#e2e2e3" stroke-width="1.5"/>
        <g class="ring-body">
            {{-- The slots. They also take the pointer, so a lifted segment never slips out from under it. --}}
            @foreach ($areas as $i => $area)
                <circle class="ring-slot" data-hit="{{ $i }}" data-name="{{ $area->name }}" cx="200" cy="200" r="160" fill="none" stroke="#e9e9ea" stroke-width="30" pathLength="360"
                        stroke-dasharray="35 400" transform="rotate({{ -90 + $i * 40 + 2.5 }} 200 200)"/>
            @endforeach
            @foreach ($areas as $i => $area)
                {{-- Lifted outward along the middle of its own arc. --}}
                @php($mid = deg2rad(-70 + $i * 40))
                <g class="ring-lift" data-lift="{{ $i }}" style="--dx: {{ round(cos($mid) * 12, 2) }}px; --dy: {{ round(sin($mid) * 12, 2) }}px">
                    <circle class="ring-seg" style="--i: {{ $i }}" data-seg="{{ $i }}" cx="200" cy="200" r="160" fill="none" stroke="#ef3340" stroke-width="30" pathLength="360"
                            stroke-dasharray="35 400" transform="rotate({{ -90 + $i * 40 + 2.5 }} 200 200)"/>
                </g>
            @endforeach
        </g>
        @if ($centre === 'mark')
            {{-- The Africa Alliance mark: the YMCA triangle over Africa. --}}
            <g class="ring-mark">
                <image class="ring-mark-img" href="{{ asset('images/aaymca-africa-mark.png') }}" x="100" y="98.5" width="200" height="203"/>
            </g>
        @endif
    </svg>
    @if ($centre === 'mark')
        <div class="absolute inset-0 m-auto w-[54%] h-[54%] pointer-events-none" aria-hidden="true">
            @foreach ($areas as $i => $area)
                <div class="ring-info" data-info="{{ $i }}">
                    <span class="landing-badge"><x-landing.icon :name="$area->code" /></span>
                    <p class="mt-sm text-[0.8125rem] font-bold tabular-nums tracking-[0.12em] text-brand-red-ink">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) count($areas), 2, '0', STR_PAD_LEFT) }}</p>
                    <p class="mt-xs text-[1rem] sm:text-[1.1875rem] font-bold leading-tight text-primary">{{ $area->name }}</p>
                    <p class="hidden sm:block mt-xs text-[0.8125rem] leading-snug text-on-surface-variant">{{ $why[$area->code] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @else
        <p class="absolute inset-0 m-auto flex items-center justify-center w-[46%] text-center text-[1.0625rem] sm:text-[1.25rem] font-bold leading-tight text-primary pointer-events-none" data-ring-label aria-hidden="true">{{ $label }}</p>
    @endif
</div>
