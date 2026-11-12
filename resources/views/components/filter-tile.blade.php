{{--
    A count that is also a choice (design standard of 10 Nov 2026): a small uppercase label,
    the count, and a line beneath (the slot). The chosen one has a dark ring. Used for My
    Work's groups and the Timelines filters.
      href    where choosing it goes
      active  true when it is the one chosen
      tone    null, or critical (the count in red: something is late)
--}}
@props(['href', 'label', 'value', 'active' => false, 'tone' => null])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'flex flex-col gap-[6px] rounded-2xl border bg-surface-container-lowest px-[18px] py-[16px] shadow-[0_1px_2px_rgba(20,20,20,0.04)] transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
    'border-on-surface ring-1 ring-on-surface' => $active,
    'border-[#e7e5e0] hover:border-[#cfccc4]' => ! $active,
]) }}>
    <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">{{ $label }}</span>
    <span @class(['text-[1.75rem] font-bold leading-tight tabular-nums', 'text-critical-ink' => $tone === 'critical' && (int) $value > 0, 'text-on-surface' => ! ($tone === 'critical' && (int) $value > 0)])>{{ $value }}</span>
    @if ($slot->isNotEmpty())
        <span class="text-[0.8125rem] text-on-surface-variant">{{ $slot }}</span>
    @endif
</a>
