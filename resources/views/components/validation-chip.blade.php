{{-- The ODP is "Validated" once the Board Chairperson has signed it; until then "Not validated". The form and report are never signed. --}}
@props(['status'])
@if ($status->validated)
    <x-chip tone="good" icon="verified" {{ $attributes }}>Validated{{ $status->validated_at ? ' · '.$status->validated_at->format('j M Y') : '' }}</x-chip>
@else
    <x-chip tone="neutral" icon="gpp_maybe" {{ $attributes }}>Not validated</x-chip>
@endif
