{{--
    When something is due, as quiet text with a clock (design standard of 10 Nov 2026):
    red once overdue, amber within 3 days, grey otherwise. Nothing when there is no date.
--}}
@props(['days' => null])
@if ($days !== null)
    @php
        [$text, $tone] = match (true) {
            $days < 0 => [$days === -1 ? __('oha.work.overdue_one') : __('oha.work.overdue', ['days' => abs($days)]), 'text-critical-ink font-semibold'],
            $days === 0 => [__('oha.work.due_today'), 'text-warning-ink font-semibold'],
            $days <= 3 => [$days === 1 ? __('oha.work.due_tomorrow') : __('oha.work.due_in', ['days' => $days]), 'text-warning-ink font-semibold'],
            default => [__('oha.work.due_in', ['days' => $days]), 'text-on-surface-variant'],
        };
    @endphp
    <span {{ $attributes->class(['inline-flex items-center gap-[5px] text-[0.8125rem] whitespace-nowrap', $tone]) }}>
        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $days < 0 ? 'alarm' : 'schedule' }}</span>{{ $text }}
    </span>
@endif
