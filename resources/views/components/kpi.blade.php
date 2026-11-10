{{--
    One key figure, as a tile (design standard of 10 Nov 2026): a small uppercase label,
    the figure, and what it means beneath (the default slot). Optional slots: `badge` (top
    right, e.g. a status pill) and `visual` (to the left, e.g. a progress ring).
      tone  null, or critical (the figure in red: something is wrong)
--}}
@props(['label', 'value' => null, 'unit' => null, 'tone' => null])
<x-panel {{ $attributes->class('!px-[20px] !py-[18px] flex gap-md') }}>
    @isset($visual)
        <div class="flex-none self-center">{{ $visual }}</div>
    @endisset
    <div class="flex-1 min-w-0 flex flex-col gap-[6px]">
        <div class="flex items-center justify-between gap-sm min-h-[1.5rem]">
            <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $label }}</p>
            {{ $badge ?? '' }}
        </div>
        <p @class([
            'font-bold tabular-nums leading-tight tracking-[-0.01em]',
            'text-[1.625rem]' => mb_strlen((string) $value) > 6,
            'text-[2rem]' => mb_strlen((string) $value) <= 6,
            'text-critical-ink' => $tone === 'critical',
            'text-on-surface' => $tone !== 'critical',
        ])>{{ $value }}@if ($unit)<span class="text-[1rem] font-semibold text-on-surface-variant">{{ $unit }}</span>@endif</p>
        @if ($slot->isNotEmpty())
            <div class="text-[0.8125rem] text-on-surface-variant">{{ $slot }}</div>
        @endif
    </div>
</x-panel>
