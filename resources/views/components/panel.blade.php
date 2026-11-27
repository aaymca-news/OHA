{{--
    A card in the site's newer, calmer style (the design standard of 10 Nov 2026): white,
    softly rounded, a light border and shadow. Optional `title` and `subtitle` make a
    heading row; `actions` (a slot) sits at its right. `flush` drops the inner padding
    (for lists that run edge to edge). `stack` spaces out what it holds, one under another.
    `info` (a key in App\Support\Kpis) puts an ⓘ beside the title.
--}}
@props(['title' => null, 'subtitle' => null, 'flush' => false, 'stack' => false, 'info' => null])
{{-- min-w-0: in a row of cards, a wide table scrolls inside its card instead of pushing the page sideways. --}}
<section {{ $attributes->class(['min-w-0 bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)]', 'p-lg' => ! $flush, 'overflow-hidden' => $flush, 'flex flex-col gap-md' => $stack]) }}>
    @if ($title || isset($actions))
        <div @class(['flex flex-wrap items-start justify-between gap-sm', 'mb-md' => ! $flush && ! $stack, 'px-lg py-md border-b border-[#efede8]' => $flush])>
            <div class="flex flex-col gap-xs min-w-0">
                @if ($title)<h2 class="flex items-center gap-[6px] text-[1.125rem] font-bold text-on-surface">{{ $title }}@if ($info)<x-kpi-info :key="$info" />@endif</h2>@endif
                @if ($subtitle)<p class="text-[0.875rem] text-on-surface-variant">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap items-center gap-sm">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
