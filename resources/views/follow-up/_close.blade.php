{{--
    Closing the follow-up cycle (Stage 2 done, decided 12 Nov 2026). It takes the overall
    review, and the three follow-ups closed: then the movement's assessors or an
    Administrator close it. Before the three follow-ups are closed ("early"), only an
    Administrator can, saying why. Tasks still open are shown, and said again before
    closing: they stay on the record for the next assessment. Expects $assessment,
    $meetings, $review, $tasks, $closing, $leftOpen, $canClose, $early.
--}}
@php
    $closed = $assessment->isFollowUpClosed();
    $locked = collect($meetings)->where('state', \App\Oha\FollowUpSchedule::LOCKED)->count();
    $open = $tasks->reject->isSettled()->count();
    $mayCloseEarly = $canClose && auth()->user()->isAdmin();
    $checks = [
        ['done' => $review !== null, 'needed' => 'needed to close', 'title' => 'Overall review written', 'sub' => $review ? 'Written '.$review->updated_at->asDay() : 'What was achieved, what was not and why, the lessons'],
        ['done' => $meetings !== [] && ! $early, 'needed' => 'needed, unless an Administrator closes early', 'title' => 'Three follow-ups closed', 'sub' => $locked.' of '.count($meetings).' closed'],
        ['done' => $open === 0, 'needed' => 'optional', 'title' => 'ODP tasks', 'sub' => $open === 0 ? 'All done, carried over or dropped' : $open.' not done: they stay on the record for the next assessment'],
    ];
    $confirm = 'Close this ODP’s follow-up cycle?'.($leftOpen !== [] ? ' It closes with '.implode(' and ', $leftOpen).': they stay on the record, and the next assessment takes them up.' : '').' The follow-up is then kept as a record and can no longer be changed.';
@endphp
<x-panel id="close" title="Close this cycle" :subtitle="$closed
    ? 'Closed '.$assessment->follow_up_closed_at->asDay().($assessment->followUpCloser ? ' by '.$assessment->followUpCloser->name : '').($assessment->follow_up_closed_reason ? ', before its three follow-ups: “'.$assessment->follow_up_closed_reason.'”' : '').'. The follow-up is kept as a record.'
    : 'Once the overall review is written and the three follow-ups are closed, the cycle closes: what is still open goes to the next assessment.'">
    @if (! $closed && $canClose && $closing === [] && ! $early)
        <x-slot:actions>
            <x-confirm-form :action="route('follow-up.close', $assessment)" :confirm="$confirm">
                <x-button>Close this cycle</x-button>
            </x-confirm-form>
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
                        <span class="font-normal text-on-surface-variant">· {{ $check['needed'] }}</span></span>
                    <span class="text-[0.8125rem] text-on-surface-variant">{{ $check['sub'] }}</span>
                </span>
            </li>
        @endforeach
    </ul>
    @if (! $closed && $closing !== [])
        <p class="mt-md text-[0.8125rem] text-on-surface-variant">To close: write the overall review first.</p>
    @elseif (! $closed && $early && $mayCloseEarly)
        {{-- An Administrator may close before the three follow-ups, saying why. --}}
        <details class="mt-md text-[0.875rem]">
            <summary class="cursor-pointer font-semibold w-fit">Close the cycle early…</summary>
            <x-confirm-form :action="route('follow-up.close', $assessment)" :confirm="$confirm" class="flex flex-col gap-sm mt-sm p-md rounded-xl border border-[#e7e5e0] bg-[#fafaf8]">
                <x-field label="Why it closes before its three follow-ups" size="sm">
                    <textarea name="reason" rows="2" required maxlength="2000" @class([...\App\Support\Ui::control(false, 'sm'), 'w-full' => true])></textarea>
                </x-field>
                <p class="text-[0.8125rem] text-on-surface-variant">The reason is kept. The follow-ups not closed are recorded as not held.</p>
                <x-button variant="secondary" size="sm" class="self-start">Close the cycle early</x-button>
            </x-confirm-form>
        </details>
    @elseif (! $closed && $early)
        <p class="mt-md text-[0.8125rem] text-on-surface-variant">To close: close the three follow-ups first. Only an Administrator can close the cycle before them, with a reason.</p>
    @endif
</x-panel>
