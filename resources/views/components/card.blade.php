{{--
    A card: a titled section of a page, in the site's newer style (design standard of
    10 Nov 2026, the same look as x-panel): white, softly rounded, a light border and shadow.
    `actions` (slot) sits at the top right.
      padding  md (default: roomier from sm) · lg (roomy everywhere)
--}}
@props(['title' => null, 'subtitle' => null, 'padding' => 'md'])
<section {{ $attributes->class([
    'bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)] flex flex-col gap-md',
    'p-md sm:p-lg' => $padding !== 'lg',
    'p-lg' => $padding === 'lg',
]) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start gap-sm">
            <div class="flex-1 min-w-[min(12rem,100%)] flex flex-col gap-xs">
                @if ($title)
                    <h2 class="text-[1.125rem] font-bold text-on-surface">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="text-[0.875rem] text-on-surface-variant">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $actions ?? '' }}
        </header>
    @endif
    {{ $slot }}
</section>
