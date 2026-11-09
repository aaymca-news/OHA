{{-- One choice in an <x-segmented>: current when `active`. --}}
@aware(['variant' => 'pills'])
@props(['href', 'active' => false, 'icon' => null])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'inline-flex items-center gap-xs',
    'px-md py-1.5 rounded-full border-[1.5px]' => $variant === 'pills',
    'border-primary bg-primary text-on-primary font-semibold' => $variant === 'pills' && $active,
    'border-outline-variant hover:border-primary' => $variant === 'pills' && ! $active,
    'px-sm py-1.5' => $variant === 'joined',
    'bg-primary text-on-primary' => $variant === 'joined' && $active,
    'hover:bg-surface-container' => $variant === 'joined' && ! $active,
]) }}>
    @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif{{ $slot }}
</a>
