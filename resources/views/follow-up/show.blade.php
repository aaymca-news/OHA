{{--
    Stage 2, ODP follow-up for one closed assessment, in the site's newer style (design
    standard of 10 Nov 2026): where the ODP stands at a glance, the follow-up year as a
    timeline, the tasks (each updated in a side panel, evidence included), the meetings,
    the overall review, and closing the cycle. Expects what FollowUpController::show gives.
--}}
@php
    $movement = $assessment->movement;
    $me = auth()->user();
    $closed = $assessment->isFollowUpClosed();
    $canPause = ! $closed && $me->can('pauseFollowUp', $movement);
    $allTasks = $tasks;
    $statuses = $allTasks->mapWithKeys(fn ($t) => [$t->id => $t->followUpStatus()]);
    $tabCount = fn (string $key) => $statuses->where('key', $key)->count();
    // The follow-up under way: the first not closed (open, or still to open).
    $nextMeeting = collect($meetings)->first(fn ($m) => in_array($m['state'], [\App\Oha\FollowUpSchedule::OPEN, \App\Oha\FollowUpSchedule::UPCOMING], true));
    $relative = fn ($date) => $date->isToday() ? 'today' : $date->copy()->startOfDay()->diffForHumans(today(), ['syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, 'parts' => 1]);

    // The one next step, as the "Next" card says it.
    $nextStep = match (true) {
        $closed => ['title' => 'Cycle complete', 'sub' => 'Closed '.$assessment->follow_up_closed_at->asDay().($assessment->followUpCloser ? ' by '.$assessment->followUpCloser->name : '').'. The movement is ready for its next assessment.'],
        // The three follow-ups closed and the overall review written: the cycle can close.
        $review !== null && ! $early => ['title' => 'Close this cycle', 'sub' => 'The three follow-ups are closed and the overall review is written. What is still open goes to the next assessment.'],
        $nextMeeting !== null => [
            'title' => 'Follow-up '.$nextMeeting['number'].' · '.($nextMeeting['due_on']?->asDay() ?? 'date not set'),
            'sub' => ($nextMeeting['due_on'] !== null ? ucfirst($relative($nextMeeting['due_on'])).'. ' : 'Agree its date with the movement. ')
                .($nextMeeting['state'] === \App\Oha\FollowUpSchedule::OPEN
                    ? 'It is open: agree where each task stands with the General Secretary, record the meeting, then close it.'
                    : 'Open it when it is time; evidence added meanwhile counts in it.'),
        ],
        $review !== null => ['title' => 'Close this cycle', 'sub' => 'The overall review is written. An Administrator can close the cycle early, with a reason.'],
        default => ['title' => 'Overall review', 'sub' => 'What the ODP achieved, what it did not and why, and the lessons. Then the cycle can close.'],
    };
@endphp
<x-layouts.app title="ODP follow-up" :subtitle="$movement->name.' · '.$assessment->period_label.($closedOn ? ' · assessment closed on '.$closedOn->asDay() : '')">
    <x-button variant="link" icon="arrow_back" :href="route('movements.show', $movement)" class="self-start">Back to {{ $movement->name }}</x-button>

    @if ($closed)
        <x-callout tone="good" icon="task_alt" title="Cycle complete">
            This ODP’s follow-up was closed on {{ $assessment->follow_up_closed_at->asDay() }}{{ $assessment->followUpCloser ? ' by '.$assessment->followUpCloser->name : '' }}. It is kept as a record, as it stood then.
        </x-callout>
    @endif

    {{-- At a glance --}}
    <section aria-label="At a glance" class="grid gap-md [grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))]">
        <x-panel class="flex items-center gap-lg">
            <x-progress-ring :value="$counts['done']" :max="$counts['total']" />
            <div class="flex flex-col gap-xs">
                <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Tasks done</span>
                <span class="text-[1.375rem] font-bold">{{ $counts['done'] }} of {{ $counts['total'] }}</span>
                <span class="text-[0.84375rem] text-on-surface-variant">As set at the last follow-up meeting</span>
            </div>
        </x-panel>
        <x-panel class="flex flex-col gap-sm !border-on-surface">
            <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Next</span>
            <span class="text-[1.25rem] font-bold">{{ $nextStep['title'] }}</span>
            <span class="text-[0.875rem] text-on-surface-variant">{{ $nextStep['sub'] }}</span>
        </x-panel>
        <x-panel class="flex flex-col gap-sm">
            <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Needs attention</span>
            @php($late = collect($meetings)->firstWhere('overdue', true))
            @if ($counts['blocked'] > 0 || $late)
                <div class="flex flex-wrap gap-sm">
                    @if ($late)<x-status-pill tone="critical">Follow-up {{ $late['number'] }} overdue</x-status-pill>@endif
                    @if ($counts['blocked'] > 0)<x-status-pill tone="critical">{{ $counts['blocked'] }} blocked</x-status-pill>@endif
                </div>
                <span class="text-[0.84375rem] text-on-surface-variant">Blocked as agreed at the last follow-up; overdue once its agreed date has passed.</span>
            @else
                <x-status-pill tone="good" class="self-start">Nothing needs attention</x-status-pill>
            @endif
        </x-panel>
    </section>

    @include('follow-up._timeline')

    {{-- Pausing the follow-up (Administrators) --}}
    @if ($movement->followUpPaused())
        <x-callout tone="warning" icon="pause_circle" :title="'Follow-up paused until '.$movement->follow_up_paused_until->asDay()">
            <p>{{ $movement->follow_up_pause_reason }}@if ($movement->pausedBy) <span class="text-[0.8125rem]">(agreed by {{ $movement->pausedBy->name }})</span>@endif</p>
            <p class="text-[0.8125rem]">No reminders are sent meanwhile, and overdue tasks and meetings are not chased.</p>
            @if ($canPause)
                <form method="POST" action="{{ route('follow-up.pause', $assessment) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="resume" value="1">
                    <x-button variant="secondary" size="sm" icon="play_circle">Lift the pause now</x-button>
                </form>
            @endif
        </x-callout>
    @elseif ($canPause)
        <x-card.details title="Pause the follow-up" subtitle="For board elections, a crisis or another agreed reason: no reminders until the end date.">
            <form method="POST" action="{{ route('follow-up.pause', $assessment) }}" class="flex flex-col gap-sm">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap items-end gap-sm">
                    <x-input name="until" type="date" label="Paused until" size="sm" :min="today()->toDateString()" required />
                    <x-input name="reason" label="Why" size="sm" wrap="flex-1 min-w-[min(16rem,100%)]" class="w-full" maxlength="1000" required />
                </div>
                <x-button variant="secondary" size="sm" icon="pause_circle" class="self-start">Pause the follow-up</x-button>
            </form>
        </x-card.details>
    @endif

    {{-- The tasks --}}
    <section aria-label="ODP tasks" class="flex flex-col gap-md" x-data="{ filter: 'all' }">
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <h2 class="text-[1.125rem] font-bold">ODP tasks</h2>
            @if ($allTasks->isNotEmpty())
                <div role="group" aria-label="Show tasks" class="flex flex-wrap gap-sm">
                    @foreach (['all' => 'All', 'blocked' => 'Blocked', 'progress' => 'Not done yet', 'done' => 'Done'] as $key => $label)
                        <button type="button" x-on:click="filter = '{{ $key }}'" x-bind:aria-pressed="filter === '{{ $key }}'"
                                class="min-h-[40px] rounded-full border px-[14px] text-[0.84375rem] font-semibold"
                                x-bind:class="filter === '{{ $key }}' ? 'bg-on-surface text-surface-container-lowest border-on-surface' : 'bg-surface-container-lowest text-on-surface-variant border-[#e7e5e0] hover:bg-surface-container-low'">
                            {{ $label }} · {{ $key === 'all' ? $allTasks->count() : $tabCount($key) }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @forelse ($priorities as $priority => $group)
            <x-panel flush :title="\Illuminate\Support\Str::title(mb_strtolower($priority))" :subtitle="$group->where('done', true)->count().' of '.$group->count().' done'">
                <ul class="flex flex-col">
                    @foreach ($group as $task)
                        @include('follow-up._task', ['task' => $task, 'status' => $statuses[$task->id]])
                    @endforeach
                </ul>
            </x-panel>
        @empty
            <x-empty-state icon="checklist">This ODP has no tasks in the platform yet. Its assessors can add them on Timelines.</x-empty-state>
        @endforelse
    </section>

    @include('follow-up._meetings', ['tasks' => $allTasks])

    @include('follow-up._odp-revisions')

    @include('follow-up._review', ['tasks' => $allTasks])

    @include('follow-up._close')

</x-layouts.app>
