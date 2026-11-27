{{--
    One key figure, as a tile (design standard of 10 Nov 2026): a small uppercase label,
    the figure, and what it means beneath (the default slot). Optional slots: `badge` (top
    right, e.g. a status pill) and `visual` (to the left, e.g. a progress ring).
      tone  null, or critical (the figure in red: something is wrong)
      info  null, or the figure's key in App\Support\Kpis: an ⓘ beside the label
--}}
@props(['label', 'value' => null, 'unit' => null, 'tone' => null, 'info' => null])
{{--
    Laid out by the tile's own width (a container query, 27 Nov 2026): in a narrow tile the
    visual sits above the figures instead of squeezing them; the label wraps with its ⓘ at
    the end of its words; the badge drops beneath the label when there is no room beside it;
    the figure and its unit ("11 / 23") never break apart.
--}}
<x-panel {{ $attributes->class('!px-[20px] !py-[18px] @container') }}>
    <div class="flex flex-col gap-sm @min-[17rem]:flex-row @min-[17rem]:gap-md">
    @isset($visual)
        <div class="flex-none @min-[17rem]:self-center">{{ $visual }}</div>
    @endisset
    <div class="flex-1 min-w-0 flex flex-col gap-[6px]">
        <div class="flex flex-wrap items-start justify-between gap-x-sm gap-y-xs min-h-[1.5rem]">
            <p class="min-w-0 text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $label }}@if ($info)<x-kpi-info :key="$info" class="ml-[6px] -mt-[2px]" />@endif</p>
            {{ $badge ?? '' }}
        </div>
        {{-- A figure and its unit ("11 / 23") never break; words ("Comms and Financial") may wrap. --}}
        <p @class([
            'font-bold tabular-nums leading-tight tracking-[-0.01em]',
            'whitespace-nowrap' => mb_strlen((string) $value) <= 8,
            'text-[1.625rem]' => mb_strlen((string) $value) > 6,
            'text-[2rem]' => mb_strlen((string) $value) <= 6,
            'text-critical-ink' => $tone === 'critical',
            'text-on-surface' => $tone !== 'critical',
        ])>{{ $value }}@if ($unit)<span class="text-[1rem] font-semibold text-on-surface-variant">{{ $unit }}</span>@endif</p>
        @if ($slot->isNotEmpty())
            <div class="text-[0.8125rem] text-on-surface-variant">{{ $slot }}</div>
        @endif
    </div>
    </div>
</x-panel>
