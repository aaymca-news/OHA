{{-- A quiet link onwards, e.g. "Full scores ›" at a panel's top right (design standard of 10 Nov 2026). --}}
@props(['href'])
<a href="{{ $href }}" {{ $attributes->class('inline-flex items-center gap-1 px-[10px] py-1.5 -my-1.5 rounded-lg text-[0.84375rem] font-semibold text-on-surface no-underline hover:bg-[#f2f1ee] focus-visible:outline-2 focus-visible:outline-primary') }}>
    {{ $slot }}
    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
</a>
