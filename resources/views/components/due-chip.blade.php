{{-- When the holder must act: overdue red, within 3 days amber, otherwise neutral. --}}
@props(['days' => null])
@if ($days === null)
    <x-chip tone="neutral" icon="event_busy">{{ __('oha.work.no_due') }}</x-chip>
@elseif ($days < 0)
    <x-chip tone="critical" icon="alarm">{{ $days === -1 ? __('oha.work.overdue_one') : __('oha.work.overdue', ['days' => abs($days)]) }}</x-chip>
@elseif ($days === 0)
    <x-chip tone="warning" icon="alarm">{{ __('oha.work.due_today') }}</x-chip>
@elseif ($days <= 3)
    <x-chip tone="warning" icon="schedule">{{ $days === 1 ? __('oha.work.due_tomorrow') : __('oha.work.due_in', ['days' => $days]) }}</x-chip>
@else
    <x-chip tone="neutral" icon="schedule">{{ __('oha.work.due_in', ['days' => $days]) }}</x-chip>
@endif
