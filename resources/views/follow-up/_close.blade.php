{{--
    Closing the follow-up cycle (Stage 2 done). The overall review is what it takes; the
    meetings held and the tasks still open are shown, and said again before closing: a
    cycle may close after one meeting, with tasks open, which stay on the record for the
    next assessment. For the movement's assessors and the Administrators. Expects
    $assessment, $meetings, $review, $tasks, $closing, $leftOpen, $canClose.
--}}
@php
    $closed = $assessment->isFollowUpClosed();
    $held = collect($meetings)->whereNotNull('meeting')->count();
    $open = $tasks->reject->isSettled()->count();
    $checks = [
        ['done' => $review !== null, 'needed' => true, 'title' => 'Overall review written', 'sub' => $review ? 'Written '.$review->updated_at->asDay() : 'What was achieved, what was not and why, the lessons'],
        ['done' => $meetings !== [] && $held === count($meetings), 'needed' => false, 'title' => 'Follow-up meetings', 'sub' => $held.' of '.count($meetings).' held'],
        ['done' => $open === 0, 'needed' => false, 'title' => 'ODP tasks', 'sub' => $open === 0 ? 'All done, carried over or dropped' : $open.' not done: they stay on the record for the next assessment'],
    ];
    $confirm = 'Close this ODP’s follow-up cycle?'.($leftOpen !== [] ? ' It closes with '.implode(' and ', $leftOpen).': they stay on the record, and the next assessment takes them up.' : '').' The follow-up is then kept as a record and can no longer be changed.';
@endphp
<x-panel id="close" title="Close this cycle" :subtitle="$closed ? 'Closed '.$assessment->follow_up_closed_at->asDay().($assessment->followUpCloser ? ' by '.$assessment->followUpCloser->name : '').': the follow-up is kept as a record.' : 'Once the overall review is written, the cycle can close, even before all three meetings: what is still open goes to the next assessment.'">
    @if (! $closed && $canClose)
        <x-slot:actions>
            @if ($closing === [])
                <x-confirm-form :action="route('follow-up.close', $assessment)" :confirm="$confirm">
                    <x-button icon="task_alt">Close this cycle</x-button>
                </x-confirm-form>
            @else
                <x-button type="button" icon="task_alt" disabled aria-describedby="close-missing">Close this cycle</x-button>
            @endif
        </x-slot:actions>
    @endif
    <ul class="grid gap-md [grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))]">
        @foreach ($checks as $check)
            <li class="flex items-start gap-sm rounded-xl border border-[#efede8] p-md">
                @if ($check['done'])
                    <span class="material-symbols-outlined text-[1.25rem] text-band-strong" aria-hidden="true">check_circle</span>
                @else
                    <span class="mt-[2px] size-[20px] flex-none rounded-full border-2 border-[#b8b6af]" aria-hidden="true"></span>
                @endif
                <span class="flex flex-col gap-[2px]">
                    <span class="text-[0.875rem] font-semibold">{{ $check['title'] }}
                        <span class="font-normal text-on-surface-variant">· {{ $check['needed'] ? 'needed to close' : 'optional' }}</span></span>
                    <span class="text-[0.8125rem] text-on-surface-variant">{{ $check['sub'] }}</span>
                </span>
            </li>
        @endforeach
    </ul>
    @if (! $closed && $closing !== [])
        <p id="close-missing" class="mt-md text-[0.8125rem] text-on-surface-variant">To close: write the overall review first.</p>
    @endif
</x-panel>
