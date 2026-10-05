@props(['icon' => 'inbox'])
<div {{ $attributes->class('flex items-center gap-sm p-md rounded-lg bg-surface-container-low text-on-surface-variant text-[0.875rem]') }}>
    <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
    <span>{{ $slot }}</span>
</div>
