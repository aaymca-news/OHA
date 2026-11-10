{{--
    The follow-up year as one line: the signing, the three meetings, the overall review,
    closing the cycle, the next assessment. Done steps green; the next one a red ring with
    a soft halo and its date in red (design standard: never a badge). Expects $signedOn,
    $meetings, $review, $closing, $nextAssessmentOn, $relative and the assessment.
--}}
@php
    $closed = $assessment->isFollowUpClosed();
    $steps = [['title' => 'ODP signed', 'when' => $signedOn?->asDay() ?? '—', 'done' => true]];
    foreach ($meetings as $m) {
        // A cycle may close before all its meetings: those then were not held.
        $when = $m['meeting'] ? 'Held '.$m['meeting']->held_on->asDay() : ($closed ? 'Not held' : $m['due_on']->asDay());
        $steps[] = ['title' => 'Follow-up '.$m['number'], 'when' => $when, 'done' => $m['meeting'] !== null, 'due' => $m['due_on']];
    }
    $steps[] = ['title' => 'Overall review', 'when' => $review ? 'Written '.$review->updated_at->asDay() : ($nextAssessmentOn ? 'Before '.$nextAssessmentOn->asMonth() : 'Before the next assessment'), 'done' => $review !== null];
    $steps[] = ['title' => 'Cycle closed', 'when' => $closed ? $assessment->follow_up_closed_at->asDay() : 'After the review', 'done' => $closed];
    $steps[] = ['title' => 'Next assessment', 'when' => $nextAssessmentOn?->asMonth() ?? 'To be set', 'done' => false, 'last' => true];
    $nextIndex = $closed ? null : collect($steps)->search(fn ($s) => ! $s['done']);
@endphp
<x-panel>
    <x-slot:actions>
        <span class="text-[0.84375rem] text-on-surface-variant">Up to three meetings, about every four months from the signing</span>
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
                    {{ $s['when'] }}@if ($isNext && isset($s['due'])) · {{ $relative($s['due']) }}@endif
                </span>
            </li>
        @endforeach
    </ol>
</x-panel>
