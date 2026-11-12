{{-- One choice in an <x-segmented>: current when `active`. --}}
@aware(['variant' => 'pills'])
@props(['href', 'active' => false, 'icon' => null])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'inline-flex items-center gap-xs',
    'min-h-9 px-[14px] rounded-full border font-semibold text-[0.84375rem]' => $variant === 'pills',
    'border-on-surface bg-on-surface text-white' => $variant === 'pills' && $active,
    'border-[#e7e5e0] bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low' => $variant === 'pills' && ! $active,
    'min-h-8 px-[12px] rounded-full font-semibold' => $variant === 'joined',
    'bg-surface-container-lowest text-on-surface shadow-[0_1px_2px_rgba(20,20,20,0.08)]' => $variant === 'joined' && $active,
    'text-on-surface-variant hover:text-on-surface' => $variant === 'joined' && ! $active,
]) }}>
    @if ($icon)<span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">{{ $icon }}</span>@endif{{ $slot }}
</a>
