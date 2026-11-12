{{--
    A button, or a link that looks like one (give it an href).
      variant  primary (default) · secondary · outline · danger · link · link-danger
      size     md (default) · sm (in lists and rows)
      icon     a Material Symbols name, shown before the text
    A plain <button> submits its form unless given type="button".
--}}
@props(['variant' => 'primary', 'size' => 'md', 'icon' => null, 'href' => null])
@php
    $link = in_array($variant, ['link', 'link-danger'], true);
    $classes = [
        'inline-flex items-center gap-xs disabled:opacity-40 disabled:cursor-not-allowed',
        'justify-center rounded-[10px] font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary' => ! $link,
        'min-h-10 px-[16px] text-[0.875rem]' => ! $link && $size !== 'sm',
        'min-h-9 px-[14px] text-[0.84375rem]' => ! $link && $size === 'sm',
        'bg-on-surface text-white hover:bg-black' => $variant === 'primary',
        'border border-[#e7e5e0] text-on-surface hover:bg-surface-container-low bg-surface-container-lowest' => $variant === 'secondary',
        'border border-on-surface text-on-surface hover:bg-surface-container-low bg-surface-container-lowest' => $variant === 'outline',
        'border border-[#ecc9c4] text-critical-ink hover:bg-critical-wash bg-surface-container-lowest' => $variant === 'danger',
        'text-on-surface font-semibold' => $variant === 'link',
        'text-critical-ink font-semibold' => $variant === 'link-danger',
    ];
@endphp
{{-- A link-style button underlines its words, not its icon. --}}
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif<span @class(['underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-current' => $link])>{{ $slot }}</span>
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif<span @class(['underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-current' => $link])>{{ $slot }}</span>
    </button>
@endif
