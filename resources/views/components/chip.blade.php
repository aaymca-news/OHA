@props(['tone' => 'neutral', 'icon' => null])
@php
    // Written out in full so Tailwind finds every class.
    $tones = [
        'good' => 'bg-good-wash text-good-ink',
        'info' => 'bg-info-wash text-info-ink',
        'warning' => 'bg-warning-wash text-warning-ink',
        'serious' => 'bg-serious-wash text-serious-ink',
        'critical' => 'bg-critical-wash text-critical-ink',
        'neutral' => 'bg-neutral-wash text-neutral-ink',
    ];
@endphp
{{-- On one line where there is room; a long chip wraps on a narrow phone instead of running off the screen. --}}
<span {{ $attributes->class(['inline-flex items-center gap-1 px-2 py-0.5 rounded-[0.875rem] text-[0.8125rem] font-semibold max-w-full sm:whitespace-nowrap', $tones[$tone] ?? $tones['neutral']]) }}>
    @if ($icon)
        <span class="material-symbols-outlined text-[0.9375rem] shrink-0" aria-hidden="true">{{ $icon }}</span>
    @endif
    {{ $slot }}
</span>
