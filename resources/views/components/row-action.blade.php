{{--
    A row's one action, quiet and outlined (design standard of 10 Nov 2026: one quiet button
    per row; the page's one dark button is its banner). When it is the step waiting on this
    person (primary), its outline is dark and an arrow leads on; otherwise it is light.
    A link when given an href, otherwise a button (type="button" unless set).
--}}
@props(['href' => null, 'primary' => false])
@php
    $classes = [
        'inline-flex items-center justify-center gap-[6px] min-h-[40px] rounded-[10px] border bg-surface-container-lowest px-[14px] text-[0.84375rem] font-semibold whitespace-nowrap hover:bg-surface-container-low',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        'border-on-surface text-on-surface' => $primary,
        'border-[#e7e5e0] text-on-surface-variant hover:text-on-surface' => ! $primary,
    ];
    $arrow = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}@if ($primary){!! $arrow !!}@endif</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}@if ($primary){!! $arrow !!}@endif</button>
@endif
