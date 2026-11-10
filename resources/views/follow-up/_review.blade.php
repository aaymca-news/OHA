{{--
    Stage 2, step 5: the overall review of the ODP (what was achieved, what was not and
    why, the lessons), and carrying unfinished tasks into the movement's next assessment.
    Expects $assessment, $review, $next, $canReview, $canCarry, $tasks.
--}}
@php
    $control = \App\Support\Ui::control(false, 'sm');
    $carriable = $tasks->filter(fn ($t) => ! $t->done && $t->carried_to_assessment_id === null && ! $t->isDropped());
    $carried = $tasks->filter(fn ($t) => $t->carried_to_assessment_id !== null);
@endphp
<x-panel id="review" title="Overall review" subtitle="What the ODP achieved, what it did not and why, and what was learned: written once the follow-up has gone as far as it will. It is what closing the cycle needs; unfinished tasks can be carried into the next assessment.">
    @if ($review)
        <div class="flex flex-col gap-sm text-[0.875rem]" x-data="{ edit: false }">
            <p class="text-[0.8125rem] text-on-surface-variant">
                Written by {{ $review->recorder?->name ?? 'an assessor' }}, {{ $review->updated_at->asDay() }} · {{ $review->tasks_done }} of {{ $review->tasks_total }} tasks done then
            </p>
            <div><p class="font-semibold">Achieved</p><p class="whitespace-pre-line">{{ $review->achieved }}</p></div>
            <div><p class="font-semibold">Not achieved, and why</p><p class="whitespace-pre-line">{{ $review->not_achieved }}</p></div>
            @if ($review->lessons)
                <div><p class="font-semibold">Lessons</p><p class="whitespace-pre-line">{{ $review->lessons }}</p></div>
            @endif
            @if ($canReview)
                <x-button type="button" variant="link" icon="edit" class="self-start" x-on:click="edit = ! edit">Correct the review</x-button>
                <div x-show="edit" x-cloak>@include('follow-up._review-form')</div>
            @endif
        </div>
    @elseif ($canReview)
        @include('follow-up._review-form')
    @else
        <x-empty-state icon="rate_review">The assessors or an Administrator write the review before the next assessment.</x-empty-state>
    @endif

    @if ($carried->isNotEmpty())
        <x-callout tone="info" icon="redo" :title="$carried->count().' '.($carried->count() === 1 ? 'task was' : 'tasks were').' carried into the next assessment'">
            <ul class="list-disc pl-md text-[0.8125rem]">
                @foreach ($carried as $task)
                    <li>{{ $task->ref ? $task->ref.' · ' : '' }}{{ $task->task }} <span class="text-on-surface-variant">→ {{ $task->carriedTo?->period_label }}</span></li>
                @endforeach
            </ul>
        </x-callout>
    @endif

    @if ($canCarry && $carriable->isNotEmpty())
        @if ($next)
            <form method="POST" action="{{ route('follow-up.carry', $assessment) }}" class="flex flex-col gap-sm p-sm rounded bg-surface-container-low">
                @csrf
                <p class="text-[0.875rem] font-semibold">Carry unfinished tasks into the {{ $next->period_label }} assessment</p>
                <ul class="flex flex-col gap-xs text-[0.875rem]">
                    @foreach ($carriable as $task)
                        <li><label class="flex items-start gap-sm"><input type="checkbox" name="tasks[]" value="{{ $task->id }}" class="mt-1">
                            <span>{{ $task->ref ? $task->ref.' · ' : '' }}{{ $task->task }} <span class="text-[0.8125rem] text-on-surface-variant">({{ $task->progress->label() }})</span></span></label></li>
                    @endforeach
                </ul>
                <p class="text-[0.8125rem] text-on-surface-variant">They are added to that assessment’s ODP tasks without a date: set new ones on Timelines.</p>
                <x-button size="sm" variant="secondary" icon="redo" class="self-start">Carry over the chosen tasks</x-button>
            </form>
        @else
            <p class="text-[0.8125rem] text-on-surface-variant">Once the movement’s next assessment is opened, the {{ $carriable->count() }} unfinished {{ $carriable->count() === 1 ? 'task' : 'tasks' }} can be carried into it from here.</p>
        @endif
    @endif
</x-panel>
