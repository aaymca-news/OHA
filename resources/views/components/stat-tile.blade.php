@props(['label', 'value', 'unit' => null])
<div {{ $attributes->class('bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-xs') }}>
    <p class="text-[0.8125rem] uppercase tracking-wider font-semibold text-on-surface-variant">{{ $label }}</p>
    <p class="text-[1.75rem] font-bold text-primary tabular-nums leading-none">{{ $value }}@if ($unit)<span class="text-[1rem] font-semibold text-on-surface-variant">{{ $unit }}</span>@endif</p>
    @isset($sub)
        <div class="text-[0.8125rem] text-on-surface-variant">{{ $sub }}</div>
    @endisset
</div>
