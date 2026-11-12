{{--
    The ODP is "Validated" once the Board Chairperson has signed it; until then "Not validated".
    The form and report are never signed. A status pill (design standard of 10 Nov 2026).
--}}
@props(['status'])
@if ($status->validated)
    <x-status-pill tone="good" {{ $attributes }}>Validated{{ $status->validated_at ? ' · '.$status->validated_at->asDay() : '' }}</x-status-pill>
@else
    <x-status-pill {{ $attributes }}>Not validated</x-status-pill>
@endif
