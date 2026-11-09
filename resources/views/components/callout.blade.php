{{--
    A coloured box that tells the reader something about the page: frozen, a problem, a
    warning, a note. The slot is the message; `title` is its bold first line.
      tone   info (default) · good · warning · serious · critical · neutral (a grey note)
      icon   a Material Symbols name (each tone has a default; icon="" for none)
      alert  true for a problem the reader must see (role="alert", with a border)
--}}
@props(['tone' => 'info', 'icon' => null, 'title' => null, 'alert' => false])
@php
    $icon ??= ['good' => 'check_circle', 'warning' => 'warning', 'serious' => 'error', 'critical' => 'error', 'neutral' => 'info'][$tone] ?? 'info';
    $washes = [
        'info' => 'bg-info-wash text-info-ink',
        'good' => 'bg-good-wash text-good-ink',
        'warning' => 'bg-warning-wash text-warning-ink',
        'serious' => 'bg-serious-wash text-serious-ink',
        'critical' => 'bg-critical-wash text-critical-ink',
        'neutral' => 'bg-surface-container text-on-surface',
    ];
    $borders = ['warning' => 'border-band-developing', 'serious' => 'border-band-atrisk', 'critical' => 'border-band-critical', 'good' => 'border-band-strong'];
@endphp
<div @if ($alert) role="alert" @endif {{ $attributes->class([
    'flex items-start gap-sm p-md rounded-lg text-[0.875rem]',
    $washes[$tone] ?? $washes['info'],
    'border-[1.5px] '.($borders[$tone] ?? 'border-outline-variant') => $alert,
]) }}>
    @if ($icon !== '')
        <span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>
    @endif
    <div class="flex-1 min-w-[min(15rem,100%)] flex flex-col gap-xs">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
