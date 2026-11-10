@props(['open' => false])
{{--
    The menu's own icon: a double chevron pointing the way the menu will go
    (« to hide it, » to show it). Drawn, so the line is thinner and softer than the icon font's.
--}}
<svg {{ $attributes->merge(['class' => 'w-[18px] h-[18px]']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @if ($open)
        <path d="M6.5 7l5 5-5 5M12.5 7l5 5-5 5"/>
    @else
        <path d="M17.5 7l-5 5 5 5M11.5 7l-5 5 5 5"/>
    @endif
</svg>
