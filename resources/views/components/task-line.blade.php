{{--
    One ODP task in a short list: the task, its number and priority, and where it stands
    (its status pill, as set at the last follow-up). Tasks have no deadlines of their own.
--}}
@props(['task'])
@php
    $status = $task->followUpStatus();
    // The task as written may run to several steps; its first line names it.
    $title = \Illuminate\Support\Str::of($task->task)->explode("\n")->first();
    $title = trim(preg_replace('/^\s*(?:[-•–]|[ivx]+\.|\d+\.)\s*/u', '', (string) $title));
@endphp
<li {{ $attributes->class('flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0') }}>
    <span class="flex-1 min-w-0">
        <span class="block text-[0.875rem] font-semibold truncate" title="{{ $task->task }}">{{ \Illuminate\Support\Str::limit($title, 90) }}</span>
        <span class="block text-[0.8125rem] text-on-surface-variant truncate">
            {{ collect([$task->ref ? 'Task '.$task->ref : null, $task->priority ? \Illuminate\Support\Str::title(mb_strtolower($task->priority)) : null])->filter()->join(' · ') }}
        </span>
    </span>
    <x-status-pill :tone="$status['tone']" class="flex-none">{{ $status['label'] }}</x-status-pill>
</li>
