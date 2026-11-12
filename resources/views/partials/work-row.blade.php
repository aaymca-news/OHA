{{--
    One My Work item as a compact row (design standard of 10 Nov 2026), like a task on the
    follow-up page: what it is, its title and one meta line (and why it is locked, when it
    is), its status pill, when it is due, and its one action. Expects $entry (an item of
    App\Queries\MyWork::for()) and $group (its group's key); App\Support\WorkRow shapes it.
--}}
@php($row = \App\Support\WorkRow::from($entry, $group ?? null))
<li class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
    <span @class([
        'flex-none hidden sm:grid size-[40px] place-items-center rounded-[12px]',
        'bg-[#f2f1ee] text-on-surface' => $row['actionable'],
        'bg-[#f7f6f3] text-on-surface-variant' => ! $row['actionable'],
    ]) aria-hidden="true">
        <span class="material-symbols-outlined text-[1.25rem]">{{ $row['icon'] }}</span>
    </span>
    <div class="flex-[999_1_16rem] min-w-0 flex flex-col gap-[2px]">
        <a href="{{ $row['url'] }}" class="text-[0.9375rem] font-semibold text-on-surface hover:underline focus-visible:underline">{{ $row['title'] }}</a>
        <span class="text-[0.8125rem] text-on-surface-variant">{{ $row['meta'] }}</span>
        @if ($row['blocked'])
            <span class="inline-flex items-start gap-[5px] text-[0.8125rem] text-on-surface-variant">
                <span class="material-symbols-outlined text-[1rem] mt-[1px]" aria-hidden="true">lock</span>{{ $row['blocked'] }}
            </span>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-x-md gap-y-sm">
        <span class="flex flex-wrap items-center gap-xs">
            <x-status-pill :tone="$row['pill'][0]">{{ $row['pill'][1] }}</x-status-pill>
            @if ($row['extra'])
                <x-status-pill :tone="$row['extra'][0]">{{ $row['extra'][1] }}</x-status-pill>
            @endif
        </span>
        <x-due-note :days="$row['days']" />
        <x-row-action :href="$row['url']" :primary="$row['primary']">
            {{ $row['button'] }}<span class="sr-only">: {{ $row['title'] }}</span>
        </x-row-action>
    </div>
</li>
