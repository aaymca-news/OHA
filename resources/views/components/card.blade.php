{{--
    A card: a titled section of a page. `actions` (slot) sits at the top right.
      padding  md (default) · lg (roomier, for forms and settings pages: p-md, p-lg from sm)
--}}
@props(['title' => null, 'subtitle' => null, 'padding' => 'md'])
<section {{ $attributes->class([
    'bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg flex flex-col gap-md',
    'p-md' => $padding !== 'lg',
    'p-md sm:p-lg' => $padding === 'lg',
]) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start gap-sm">
            <div class="flex-1 min-w-[min(12rem,100%)]">
                @if ($title)
                    <h2 class="text-[1rem] font-bold text-primary">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $actions ?? '' }}
        </header>
    @endif
    {{ $slot }}
</section>
