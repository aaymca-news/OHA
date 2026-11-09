{{--
    A health band: icon and label from the health_bands table, tone from its code.
    A movement not yet rated whose assessment has started reads "Assessment started", not "Not started".
--}}
@props(['band' => null, 'code' => null, 'label' => null, 'icon' => null, 'underWay' => false])
@php
    $code = $band?->code ?? $code ?? 'notstarted';
    $tone = ['excellent' => 'good', 'strong' => 'good', 'developing' => 'warning', 'atrisk' => 'serious', 'critical' => 'critical'][$code] ?? 'neutral';
@endphp
@if ($code === 'notstarted' && $underWay)
    <x-chip tone="info" icon="play_circle" {{ $attributes }}>Assessment started</x-chip>
@else
    <x-chip :tone="$tone" :icon="$band?->icon ?? $icon ?? 'remove'" {{ $attributes }}>{{ $band?->label ?? $label ?? 'Not started' }}</x-chip>
@endif
