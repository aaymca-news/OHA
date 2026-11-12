{{--
    The follow-up year as one line: the closing (Baseline 1), the three meetings, the overall review,
    closing the cycle, the next assessment. Done steps green; the next one a red ring with
    a soft halo and its date in red (design standard: never a badge). Expects $closedOn,
    $meetings, $review, $closing, $relative and the assessment.
--}}
@php
    $closed = $assessment->isFollowUpClosed();
    $steps = [['title' => 'Assessment closed', 'when' => $closedOn?->asDay() ?? '—', 'done' => true]];
    foreach ($meetings as $m) {
        // Done once closed (locked). A cycle may close before all of them: those then were not held.
        $locked = $m['state'] === \App\Oha\FollowUpSchedule::LOCKED;
        $when = match (true) {
            $locked => 'Closed '.$m['window']->locked_at->asDay(),
            $m['state'] === \App\Oha\FollowUpSchedule::SKIPPED => 'Not held',
            default => $m['due_on'] !== null ? 'Planned '.$m['due_on']->asDay() : 'Date not set yet',
        };
        $steps[] = ['title' => 'Follow-up '.$m['number'], 'when' => $when, 'done' => $locked, 'due' => $m['due_on']];
    }
    $steps[] = ['title' => 'Overall review', 'when' => $review ? 'Written '.$review->updated_at->asDay() : 'After the three follow-ups', 'done' => $review !== null];
    $steps[] = ['title' => 'Cycle closed', 'when' => $closed ? $assessment->follow_up_closed_at->asDay() : 'After the review', 'done' => $closed];
    $steps[] = ['title' => 'Next assessment', 'when' => $closed ? 'Ready to start' : 'Once this cycle is closed', 'done' => false, 'last' => true];
    $nextIndex = $closed ? null : collect($steps)->search(fn ($s) => ! $s['done']);
@endphp
<x-panel>
    <x-slot:actions>
        <span class="text-[0.84375rem] text-on-surface-variant">Three follow-ups, on dates agreed with the movement</span>
    </x-slot:actions>
    <x-slot:title>The follow-up year</x-slot:title>
    <ol class="grid gap-md [grid-template-columns:repeat(auto-fit,minmax(8rem,1fr))]">
        @foreach ($steps as $i => $s)
            @php($isNext = $i === $nextIndex)
            <li class="flex flex-col gap-sm" @if ($isNext) aria-current="step" @endif>
                <div class="flex items-center gap-sm h-[20px]">
                    <span @class([
                        'rounded-full flex-none box-border',
                        'size-[14px] bg-band-strong border-2 border-band-strong' => $s['done'],
                        'size-[18px] bg-surface-container-lowest border-[5px] border-brand-red-ink shadow-[0_0_0_5px_rgba(200,16,46,0.14)]' => $isNext,
                        'size-[14px] bg-surface-container-lowest border-2 border-[#c9c7c0]' => ! $s['done'] && ! $isNext,
                    ])></span>
                    @unless ($s['last'] ?? false)
                        <span @class(['h-[2px] flex-1', 'bg-band-strong' => $s['done'], 'bg-[#e2e0da]' => ! $s['done']])></span>
                    @endunless
                </div>
                <span class="text-[0.875rem] font-semibold">{{ $s['title'] }}</span>
                <span @class(['text-[0.8125rem]', 'text-brand-red-ink font-semibold' => $isNext, 'text-on-surface-variant' => ! $isNext])>
                    {{ $s['when'] }}@if ($isNext && ($s['due'] ?? null) !== null) · {{ $relative($s['due']) }}@endif
                </span>
            </li>
        @endforeach
    </ol>
</x-panel>
