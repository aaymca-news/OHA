{{--
    A card that folds: its title is always shown, the rest opens on a click. For long or
    occasional sections (history, advanced options).
      title, subtitle  shown in the summary line
      open             start open
--}}
@props(['title', 'subtitle' => null, 'open' => false])
<details {{ $attributes->class('group bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg') }} @if ($open) open @endif>
    <summary class="oha-summary flex items-start gap-sm p-md cursor-pointer list-none">
        <span class="flex-1 min-w-0">
            <span class="block text-[1rem] font-bold text-primary">{{ $title }}</span>
            @if ($subtitle)
                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $subtitle }}</span>
            @endif
        </span>
        <span class="material-symbols-outlined text-on-surface-variant transition-transform group-open:rotate-180" aria-hidden="true">expand_more</span>
    </summary>
    <div class="px-md pb-md flex flex-col gap-md">
        {{ $slot }}
    </div>
</details>
