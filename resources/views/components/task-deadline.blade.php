{{--
    One ODP task by its deadline: a date block (red once overdue), the task, its number
    and priority, and its status pill.
--}}
@props(['task'])
@php
    $status = $task->followUpStatus();
    $late = $task->state() === 'overdue';
    // The task as written may run to several steps; its first line names it.
    $title = \Illuminate\Support\Str::of($task->task)->explode("\n")->first();
    $title = trim(preg_replace('/^\s*(?:[-•–]|[ivx]+\.|\d+\.)\s*/u', '', (string) $title));
@endphp
<li {{ $attributes->class('flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0') }}>
    <span @class([
        'flex-none w-[46px] rounded-[10px] py-[5px] text-center',
        'bg-critical-wash text-critical-ink' => $late,
        'bg-[#f2f1ee] text-on-surface' => ! $late,
    ])>
        @if ($task->due_on)
            <span class="block text-[0.6875rem] font-semibold uppercase">{{ $task->due_on->format('M') }}</span>
            <span class="block text-[1.0625rem] font-bold leading-tight tabular-nums">{{ $task->due_on->format('j') }}</span>
            <span class="sr-only">{{ $task->due_on->asDay() }}</span>
        @else
            <span class="block text-[0.6875rem] font-semibold leading-[2.3]">No date</span>
        @endif
    </span>
    <span class="flex-1 min-w-0">
        <span class="block text-[0.875rem] font-semibold truncate" title="{{ $task->task }}">{{ \Illuminate\Support\Str::limit($title, 90) }}</span>
        <span class="block text-[0.78125rem] text-on-surface-variant truncate">
            {{ collect(['Task '.$task->ref, $task->priority ? \Illuminate\Support\Str::title(mb_strtolower($task->priority)) : null, $task->due_on && $task->due_on->year !== now()->year ? (string) $task->due_on->year : null])->filter()->join(' · ') }}
        </span>
    </span>
    <x-status-pill :tone="$status['tone']" class="flex-none">{{ $status['label'] }}</x-status-pill>
</li>
