@props(['title' => null, 'subtitle' => null])
<section {{ $attributes->class('bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-md') }}>
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
