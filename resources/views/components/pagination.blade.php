{{--
    Previous / next for a paged list (a LengthAwarePaginator), with where the reader is:
    "21–40 of 75". Shown only when there is more than one page. The list's filters stay in
    the links (->withQueryString()); `fragment` keeps the reader at the list (#…).
--}}
@props(['paginator', 'fragment' => null])
@if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $paginator->hasPages())
    @php
        $link = fn (?string $url) => $url !== null && $fragment !== null ? $url.'#'.$fragment : $url;
    @endphp
    <nav aria-label="Pages" {{ $attributes->class('flex flex-wrap items-center gap-sm text-[0.875rem]') }}>
        <span class="text-on-surface-variant tabular-nums">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <span class="flex gap-xs ml-auto">
            @if ($paginator->onFirstPage())
                <span class="px-md py-1.5 rounded border-[1.5px] border-surface-container text-on-surface-variant inline-flex items-center gap-xs" aria-disabled="true">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">chevron_left</span>Previous
                </span>
            @else
                <x-button :href="$link($paginator->previousPageUrl())" variant="secondary" size="sm" icon="chevron_left" rel="prev">Previous</x-button>
            @endif
            @if ($paginator->hasMorePages())
                <x-button :href="$link($paginator->nextPageUrl())" variant="secondary" size="sm" rel="next">Next<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">chevron_right</span></x-button>
            @else
                <span class="px-md py-1.5 rounded border-[1.5px] border-surface-container text-on-surface-variant inline-flex items-center gap-xs" aria-disabled="true">
                    Next<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">chevron_right</span>
                </span>
            @endif
        </span>
    </nav>
@endif
