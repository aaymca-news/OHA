{{--
    Stage 2, ODP follow-up for one signed assessment: each ODP task, where it stands, and
    reporting and confirming progress. Expects $assessment, $priorities (tasks by
    priority), $counts, $canReport, $canConfirm and $evidence (id => title).
--}}
<x-layouts.app title="ODP follow-up" :subtitle="$assessment->movement->name.' · '.$assessment->period_label.'. Stage 2: carrying out the signed ODP, task by task.'">
    <x-button variant="link" icon="arrow_back" :href="route('movements.show', $assessment->movement)" class="self-start">Back to {{ $assessment->movement->name }}</x-button>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-md">
        <x-stat-tile label="Tasks done" :value="$counts['done']" :unit="' / '.$counts['total']">
            <x-slot:sub>Confirmed by an assessor</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Waiting for confirmation" :value="$counts['waiting']">
            <x-slot:sub>Reported done by the movement</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Overdue" :value="$counts['overdue']">
            <x-slot:sub>Past their deadline, not done</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Blocked" :value="$counts['blocked']">
            <x-slot:sub>Something is in the way</x-slot:sub>
        </x-stat-tile>
    </div>

    @if ($counts['total'] > 0)
        <x-bar wide :value="$counts['done']" :max="$counts['total']" tone="good" :label="$counts['done'].' of '.$counts['total'].' done'" />
    @endif

    @php
        $movement = $assessment->movement;
        $canPause = auth()->user()->can('pauseFollowUp', $movement);
        $allTasks = $tasks; // $tasks is reused below for each priority's tasks
    @endphp
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

    <x-callout tone="info" title="How progress is reported">
        <p>The movement’s National General Secretary and its AAYMCA assessors say where each task stands. A task reported done by the General Secretary counts once an assessor has confirmed it; link the evidence that shows it done.</p>
    </x-callout>

    @include('follow-up._meetings')

    @forelse ($priorities as $priority => $tasks)
        <x-card :title="\Illuminate\Support\Str::title(mb_strtolower($priority))" :subtitle="$tasks->where('done', true)->count().' of '.$tasks->count().' done'">
            <ul class="flex flex-col">
                @foreach ($tasks as $task)
                    @include('follow-up._task', ['task' => $task])
                @endforeach
            </ul>
        </x-card>
    @empty
        <x-empty-state icon="checklist">This ODP has no tasks in the platform yet. Its assessors can add them on Timelines.</x-empty-state>
    @endforelse

    @include('follow-up._review', ['tasks' => $allTasks])
</x-layouts.app>
