{{--
    The ⓘ beside a figure (KPI dictionary, 23 Nov 2026): what the figure means and how it is
    worked out, from App\Support\Kpis, in a small card that opens on hover, focus or a tap
    and closes on Escape, a tap elsewhere or leaving it. Every part is a span, so it can sit
    inside a heading or a label.
      key  the figure's key in App\Support\Kpis
--}}
@props(['key'])
@php($kpi = \App\Support\Kpis::get($key))
<span class="relative inline-flex align-middle normal-case tracking-normal font-normal" x-data="{ open: false }"
      x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" x-on:click="open = ! open" x-on:focus="open = true" x-on:blur="open = false"
            x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-label="What is “{{ $kpi['label'] }}”?"
            class="size-[18px] grid place-items-center rounded-full text-[#8a8880] hover:text-on-surface focus-visible:text-on-surface focus-visible:outline-2 focus-visible:outline-on-surface">
        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/></svg>
    </button>
    <span x-show="open" x-cloak x-transition.opacity role="tooltip"
          class="absolute z-30 left-1/2 -translate-x-1/2 top-full mt-xs w-[17rem] max-w-[80vw] flex flex-col gap-[6px] rounded-xl border border-[#e7e5e0] bg-surface-container-lowest px-[14px] py-[12px] text-left text-[0.8125rem] leading-snug text-on-surface shadow-[0_8px_24px_rgba(20,20,20,0.12)]">
        <span class="block font-semibold">{{ $kpi['label'] }}</span>
        <span class="block">{{ $kpi['means'] }}</span>
        <span class="block text-on-surface-variant">{{ $kpi['how'] }}</span>
        @if ($kpi['mind'])
            <span class="block text-on-surface-variant">{{ $kpi['mind'] }}</span>
        @endif
    </span>
</span>
