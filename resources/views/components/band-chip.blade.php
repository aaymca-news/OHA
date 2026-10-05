{{-- A health band: icon and label from the health_bands table, tone from its code. --}}
@props(['band' => null, 'code' => null, 'label' => null, 'icon' => null])
@php
    $code = $band?->code ?? $code ?? 'notstarted';
    $tone = ['excellent' => 'good', 'strong' => 'good', 'developing' => 'warning', 'atrisk' => 'serious', 'critical' => 'critical'][$code] ?? 'neutral';
@endphp
<x-chip :tone="$tone" :icon="$band?->icon ?? $icon ?? 'remove'" {{ $attributes }}>{{ $band?->label ?? $label ?? 'Not started' }}</x-chip>
