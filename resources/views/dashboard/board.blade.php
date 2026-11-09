@use('App\Support\Viz')
{{--
    A National Movement's own dashboard, for its Board Chairperson and National General
    Secretary: their movement at a glance.
--}}
<x-layouts.app :title="$status->name" subtitle="Your movement at a glance: its organizational health, where its assessment stands, and how its ODP is going.">
    @php
        $open = $tasks->where('done', false);
        $overdue = $tasks->filter(fn ($t) => $t->state() === 'overdue')->count();
        $holder = $current?->workItem;
    @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-md">
        <x-stat-tile label="Health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:sub><x-band-chip :band="$status->band" :under-way="$holder !== null" /></x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Next assessment" :value="$status->next_assessment_on?->asMonth() ?? 'Not scheduled'">
            <x-slot:sub>{{ $status->reassessment_overdue ? 'Past its date' : ($latest ? 'Last: '.$latest->period_label : 'Not yet assessed') }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="ODP tasks done" :value="$odpAssessment ? $tasks->where('done', true)->count() : '—'" :unit="$odpAssessment ? ' / '.$tasks->count() : null">
            <x-slot:sub>{{ $odpAssessment ? 'In your ODP for '.$odpAssessment->period_label : 'No approved ODP yet' }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="ODP tasks overdue" :value="$odpAssessment ? $overdue : '—'">
            <x-slot:sub>{{ $odpAssessment ? $open->count().' still open' : 'Shown once your ODP is approved' }}</x-slot:sub>
        </x-stat-tile>
    </div>

    @if ($work->isNotEmpty() || auth()->user()->role === \App\Enums\Role::Board)
        <x-card title="Waiting on you">
            <x-slot:actions><a href="{{ route('my-work') }}" class="text-[0.875rem] text-primary underline">Open My Work</a></x-slot:actions>
            @if ($work->isEmpty())
                <x-empty-state icon="task_alt">Nothing is waiting on you. When your ODP is approved, it comes here for your signature.</x-empty-state>
            @else
                <ul class="flex flex-col gap-sm">
                    @foreach ($work as $entry)
                        @include('partials.work-row', ['entry' => $entry])
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif

    <x-card title="Where your assessment stands" :subtitle="$current ? $current->period_label.($holder ? ' · '.$holder->holder_role->label() : '') : null">
        @if ($current && $milestones->isNotEmpty())
            <x-gate-stepper :milestones="$milestones" :current="$holder?->holder_role->value === 'board' ? 'board' : $holder?->artefact_kind->value" />
            <p class="text-[0.8125rem] text-on-surface-variant">AAYMCA checks your OHA form, writes the report and agrees the ODP with you; the Board Chairperson then signs the ODP.</p>
        @elseif ($lastDone)
            {{-- Stage 1 done: the ODP is signed; the follow-ups are what is under way now. --}}
            <div class="flex flex-wrap items-center gap-sm">
                <x-chip tone="good" icon="verified">Stage 1 done</x-chip>
                <x-chip tone="info" icon="autorenew">Follow-ups ongoing</x-chip>
            </div>
            <p class="text-[0.875rem]">
                Your {{ $lastDone->period_label }} assessment is done: the ODP is signed by the board, and its follow-ups are under way.
                {{ $status->next_assessment_on ? 'The next assessment is due in '.$status->next_assessment_on->format('F Y').'.' : '' }}
            </p>
        @else
            <x-empty-state icon="event_available">
                No assessment is under way.
                {{ $status->next_assessment_on ? 'The next one is due in '.$status->next_assessment_on->format('F Y').'.' : '' }}
            </x-empty-state>
        @endif
    </x-card>

    <div class="grid lg:grid-cols-2 gap-md items-start">
        <x-card title="Scores by category" :subtitle="$latest ? 'From your approved OHA form, '.$latest->period_label.'.' : null">
            @if ($latest)
                <x-score-table :rows="$rows" :total="$latest->score" />
            @else
                <x-empty-state icon="insights">Your scores appear here once your OHA form is approved.</x-empty-state>
            @endif
        </x-card>

        <x-card title="Your ODP" :subtitle="$odpAssessment ? 'Its tasks and their deadlines. Your AAYMCA assessors keep them up to date.' : null">
            @if (! $odpAssessment)
                <x-empty-state icon="checklist">Your ODP’s tasks appear here once it is approved.</x-empty-state>
            @elseif ($tasks->isEmpty())
                <x-empty-state icon="checklist">Your ODP’s tasks have not been entered yet.</x-empty-state>
            @else
                <x-bar wide :value="$tasks->where('done', true)->count()" :max="$tasks->count()" tone="good" :label="$tasks->where('done', true)->count().' of '.$tasks->count().' done'" />
                @php($next = $open->sortBy(fn ($t) => [$t->due_on === null ? 1 : 0, $t->due_on?->timestamp ?? 0])->take(5))
                @if ($next->isEmpty())
                    <x-empty-state icon="task_alt">Every task is done.</x-empty-state>
                @else
                    <p class="text-[0.8125rem] font-semibold">Coming up</p>
                    <ul class="flex flex-col">
                        @foreach ($next as $task)
                            @include('timelines._task', ['task' => $task])
                        @endforeach
                    </ul>
                @endif
                @if ($tasks->count() > $next->count())
                    <details class="text-[0.875rem]">
                        <summary class="cursor-pointer text-primary underline w-fit">Every task ({{ $tasks->count() }})</summary>
                        <ul class="flex flex-col mt-xs">
                            @foreach ($tasks as $task)
                                @include('timelines._task', ['task' => $task])
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endif
        </x-card>
    </div>

    <div class="grid lg:grid-cols-2 gap-md items-start">
        <x-card title="Your movement in numbers" :subtitle="$profile ? 'As given in your approved OHA form.' : null">
            @if ($profile)
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-md">
                    @foreach ([[$profile['people']['staff'] ?? null, 'Staff'], [$profile['people']['volunteers'] ?? null, 'Volunteers'], [$profile['beneficiaries'] ?? null, 'People reached a year'], [$profile['identity']['branches'] ?? null, 'Branches'], [$profile['board']['size'] ?? null, 'Board members']] as [$value, $label])
                        <div>
                            <dt class="text-[0.8125rem] text-on-surface-variant">{{ $label }}</dt>
                            <dd class="text-[1.5rem] font-bold text-primary leading-tight">{{ $value !== null ? Viz::number($value) : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <x-empty-state icon="groups">These appear once your OHA form is approved.</x-empty-state>
            @endif
        </x-card>

        <x-card title="Your contacts at AAYMCA" subtitle="The staff assessing your movement.">
            @forelse ($assessors as $person)
                <div class="flex items-center gap-sm text-[0.875rem]">
                    <x-avatar :user="$person" />
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ $person->name }}</span>
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $person->title ?? $person->role->label() }} · <a href="mailto:{{ $person->email }}" class="text-primary underline break-all">{{ $person->email }}</a></span>
                    </span>
                </div>
            @empty
                <x-empty-state icon="support_agent">AAYMCA has not yet named who assesses your movement.</x-empty-state>
            @endforelse
        </x-card>
    </div>

    <x-card title="Your reports and ODPs" subtitle="Each assessment with something approved: open it for the report, the ODP and the scores.">
        @forelse ($assessments as $assessment)
            <a href="{{ route('assessments.show', $assessment) }}" class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                <span class="text-primary underline">{{ $status->name }} · {{ $assessment->period_label }}</span>
                @if ($assessment->score)
                    <x-band-chip :band="$assessment->score->band" />
                @endif
            </a>
        @empty
            <x-empty-state icon="folder_open">No report or ODP has been approved for your movement yet.</x-empty-state>
        @endforelse
    </x-card>
</x-layouts.app>
