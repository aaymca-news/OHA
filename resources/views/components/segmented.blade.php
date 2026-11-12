{{--
    A row of choices where one is current: filter tabs, "Cards | Table", My Work's groups.
    Fill it with <x-segmented.link :href :active icon>…</x-segmented.link>.
      variant  pills (default: separate rounded buttons) · joined (one bar, like a toggle)
      label    what the choices are, for screen readers (aria-label)
--}}
@props(['variant' => 'pills', 'label'])
<nav aria-label="{{ $label }}" {{ $attributes->class([
    'flex flex-wrap gap-xs text-[0.875rem]' => $variant === 'pills',
    'flex gap-xs rounded-full bg-[#f2f1ee] p-[3px] text-[0.8125rem]' => $variant === 'joined',
]) }}>
    {{ $slot }}
</nav>
