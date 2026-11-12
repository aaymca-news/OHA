{{--
    Each movement's ODP tasks, read from the newest ODP version, typed in when it cannot be
    read, and corrected by the assessors. Where each stands is set at the follow-ups; tasks
    have no deadlines of their own (decided 12 Nov 2026). Expects
    $assessments, $movements, $tasks, $totals, $counts, $state, $movement and $canEdit.
    The counts on top are also filters (choosing one again shows every task); then one
    folding panel per movement, its tasks as compact rows.
--}}
@php
    $filters = [
        'blocked' => ['Blocked', 'critical', 'As set at the last follow-up'],
        'progress' => ['Not done yet', null, 'Not started or in progress'],
        'done' => ['Done', null, 'Done at a follow-up'],
    ];
@endphp

<section aria-label="Filter the tasks" class="grid gap-sm sm:gap-md grid-cols-2 lg:grid-cols-3">
    @foreach ($filters as $key => [$label, $tone, $note])
        <x-filter-tile :href="route('timelines', array_filter(['tab' => 'odp', 'state' => $state === $key ? null : $key, 'movement' => $movement]))"
                       :active="$state === $key" :label="$label" :value="$counts[$key] ?? 0" :tone="$tone">
            {{ $state === $key ? 'Showing these · choose again for all' : $note }}
        </x-filter-tile>
    @endforeach
</section>

<div class="flex flex-wrap items-center gap-sm">
    <details class="text-[0.875rem]">
        <summary class="cursor-pointer font-semibold">Where these come from</summary>
        <div class="flex flex-col gap-xs mt-sm max-w-[60rem] rounded-xl bg-[#f4f3f0] p-md">
            <p>Each task is read from the movement’s newest ODP: its TASKS column and who is responsible. When a newer ODP is uploaded, its tasks are read again. The deadlines written in the ODP are not used: the follow-ups have the dates, agreed with each movement.</p>
            <p>Where each task stands (Not started, In progress, Blocked, Done) is set at each follow-up meeting, for every task together. Between the follow-ups, the General Secretary and the assessors add notes and evidence on the follow-up page.</p>
            <p>An ODP saved as a Word or PDF file cannot be read. Type its tasks in with “Add a task”. Its assessors can correct a task’s wording or who is responsible; every change is in the assessment’s audit trail.</p>
        </div>
    </details>
    @if ($movements->count() > 1)
        <form method="GET" action="{{ route('timelines') }}" class="ml-auto flex items-center gap-sm text-[0.875rem]">
            <input type="hidden" name="tab" value="odp">
            @if ($state)<input type="hidden" name="state" value="{{ $state }}">@endif
            <label for="tl-movement" class="text-on-surface-variant">Movement</label>
            <select id="tl-movement" name="movement" x-data x-on:change="$el.form.submit()" @class(\App\Support\Ui::control(false, 'sm'))>
                <option value="">All {{ $movements->count() }}</option>
                @foreach ($movements as $m)
                    <option value="{{ $m->slug }}" @selected($movement === $m->slug)>{{ $m->name }}</option>
                @endforeach
            </select>
            <noscript><x-button variant="secondary" size="sm">Show</x-button></noscript>
        </form>
    @endif
</div>

@forelse ($assessments as $assessment)
    @php
        $list = $tasks[$assessment->id] ?? collect();
        $total = $totals[$assessment->id] ?? ['all' => 0, 'done' => 0];
        $editable = $canEdit[$assessment->id] ?? false;
        $read = $assessment->odpReadDocument;
        $blocked = $list->filter(fn ($t) => $t->followUpStatus()['key'] === 'blocked')->count();
        // One movement at a time: open when it is the one chosen, or when a filter has shortened the lists.
        $expanded = $movement !== null || $state !== null || $assessments->count() === 1;
    @endphp
    <details class="group rounded-2xl border border-[#e7e5e0] bg-surface-container-lowest shadow-[0_1px_2px_rgba(20,20,20,0.04)]" @if ($expanded) open @endif>
        <summary class="oha-summary cursor-pointer list-none px-lg py-md grid sm:grid-cols-[minmax(10rem,16rem)_1fr_auto] items-center gap-x-lg gap-y-sm hover:bg-[#fafaf8] rounded-2xl group-open:rounded-b-none">
            <span class="flex items-center gap-sm min-w-0">
                <span class="material-symbols-outlined text-on-surface-variant transition-transform group-open:rotate-90" aria-hidden="true">chevron_right</span>
                <span class="min-w-0">
                    <span class="block text-[0.9375rem] font-bold truncate">{{ $assessment->movement->name }}</span>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }}</span>
                </span>
            </span>
            @if ($total['all'] > 0)
                <span class="flex items-center gap-sm" role="img" aria-label="{{ $total['done'] }} of {{ $total['all'] }} tasks done">
                    <span class="relative h-2 flex-1 rounded-full bg-[#f0eeea]">
                        <span class="absolute inset-y-0 left-0 rounded-full bg-band-strong" style="width: {{ $total['done'] > 0 ? max(1.5, $total['done'] / $total['all'] * 100) : 0 }}%"></span>
                    </span>
                    <span class="text-[0.8125rem] tabular-nums text-on-surface-variant whitespace-nowrap">{{ $total['done'] }} / {{ $total['all'] }} done</span>
                </span>
            @else
                <span class="text-[0.8125rem] text-on-surface-variant">No tasks yet</span>
            @endif
            <span class="flex flex-wrap gap-xs">
                @if ($blocked)<x-status-pill tone="critical">{{ $blocked }} blocked</x-status-pill>@endif
                @if ($assessment->odp_read_problem)<x-status-pill tone="warning">Not read</x-status-pill>@endif
            </span>
        </summary>
        <div class="flex flex-col gap-md border-t border-[#efede8] pt-md pb-lg">
            <p class="flex flex-wrap items-center gap-x-md gap-y-xs px-lg text-[0.8125rem] text-on-surface-variant">
                <span>{{ $read ? 'Read from the ODP saved '.$read->created_at->asDay() : 'Typed in' }}</span>
                <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']) }}" class="font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">Open the ODP</a>
            </p>
            @if ($assessment->odp_read_problem)
                <x-callout tone="serious" icon="info" role="status" class="mx-lg">{{ $assessment->odp_read_problem }}</x-callout>
            @endif

            @if ($list->isEmpty())
                <x-empty-state icon="checklist" class="mx-lg">{{ $state ? 'No task matches this filter.' : 'No tasks yet.' }}</x-empty-state>
            @else
                <ul class="flex flex-col border-y border-[#f2f1ec]">
                    @foreach ($list as $task)
                        @include('timelines._task', ['task' => $task, 'canEdit' => $editable])
                    @endforeach
                </ul>
            @endif

            @if ($editable)
                <details class="px-lg text-[0.875rem]">
                    <summary class="cursor-pointer list-none inline-flex items-center gap-[6px] min-h-[40px] rounded-[10px] border border-[#e7e5e0] px-[14px] font-semibold hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">add</span>Add a task
                    </summary>
                    <form method="POST" action="{{ route('timeline.tasks.store', $assessment) }}" class="flex flex-col gap-sm mt-sm rounded-xl border border-[#e7e5e0] bg-[#fafaf8] p-md">
                        @csrf
                        {{--
                            x-field around plain boxes, not x-input: every movement has this form, and each
                            task has a form with the same names, so old() values and errors would land in all of them.
                        --}}
                        <div class="grid sm:grid-cols-[6rem_1fr] gap-sm">
                            <x-field label="Number">
                                <input name="ref" maxlength="16" placeholder="1.1" @class(\App\Support\Ui::control(false, 'sm'))>
                            </x-field>
                            <x-field label="Task">
                                <input name="task" required maxlength="4000" placeholder="Develop a fundraising strategy" @class(\App\Support\Ui::control(false, 'sm'))>
                            </x-field>
                        </div>
                        <x-field label="Responsible">
                            <input name="responsible" maxlength="500" placeholder="Finance Committee" @class(\App\Support\Ui::control(false, 'sm'))>
                        </x-field>
                        <x-button class="self-start">Add the task</x-button>
                    </form>
                </details>
            @endif
        </div>
    </details>
@empty
    <x-panel>
        <x-empty-state icon="checklist">
            {{ auth()->user()->oversees() ? 'No movement has an ODP yet.' : 'None of the movements you assess has an ODP yet.' }}
            Tasks appear here once an ODP is uploaded on an assessment.
        </x-empty-state>
    </x-panel>
@endforelse
