{{--
    Where a National Movement's head office is (decided 27 Nov 2026): a pin, the words
    "National Head Office", and its city and country, so the city is never taken for the
    country's capital. The one way a movement's location shows, everywhere it shows.
      movement  the Movement (its city and country)
      size      'lg': a card in a movement's page header · 'sm' (default): one line in a list,
                card, table or search result
--}}
@props(['movement', 'size' => 'sm'])
@php($place = collect([$movement->city, $movement->country])->filter()->join(', '))
@if ($size === 'lg')
    <span {{ $attributes->class('inline-flex items-center gap-[10px] rounded-xl border border-[#e7e5e0] bg-surface-container-lowest py-[6px] pl-[6px] pr-[14px]') }}>
        <span class="flex-none grid place-items-center size-9 rounded-lg bg-[#fdecee] text-brand-red-ink" aria-hidden="true">
            <span class="material-symbols-outlined text-[1.25rem]">location_on</span>
        </span>
        <span class="flex flex-col leading-tight min-w-0">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant whitespace-nowrap">National Head Office</span>
            <span class="text-[0.9375rem] font-semibold text-on-surface">{{ $place ?: 'Not given yet' }}</span>
        </span>
    </span>
@else
    {{-- As the header's card, smaller: the caption above the place, two short lines in any column. --}}
    <span {{ $attributes->class('inline-flex items-start gap-[6px]') }}>
        <span class="material-symbols-outlined flex-none text-[1.0625rem] leading-none mt-[2px] text-brand-red-ink" aria-hidden="true">location_on</span>
        <span class="flex flex-col min-w-0 leading-tight">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant whitespace-nowrap">National Head Office</span>
            <span class="text-[0.8125rem] text-on-surface">{{ $place ?: 'Not given yet' }}</span>
        </span>
    </span>
@endif
