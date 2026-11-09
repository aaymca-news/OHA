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
        'justify-center rounded font-semibold' => ! $link,
        'px-md py-2 text-[0.875rem]' => ! $link && $size !== 'sm',
        'px-md py-1.5 text-[0.8125rem]' => ! $link && $size === 'sm',
        'bg-primary text-on-primary hover:bg-primary-container' => $variant === 'primary',
        'border-[1.5px] border-outline-variant text-primary hover:border-primary bg-surface-container-lowest' => $variant === 'secondary',
        'border-[1.5px] border-primary text-primary hover:bg-surface-container bg-surface-container-lowest' => $variant === 'outline',
        'border-[1.5px] border-error text-error hover:bg-error-container bg-surface-container-lowest' => $variant === 'danger',
        'text-primary' => $variant === 'link',
        'text-error' => $variant === 'link-danger',
    ];
@endphp
{{-- A link-style button underlines its words, not its icon. --}}
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif<span @class(['underline' => $link])>{{ $slot }}</span>
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif<span @class(['underline' => $link])>{{ $slot }}</span>
    </button>
@endif
