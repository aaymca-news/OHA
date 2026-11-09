{{--
    Each movement's ODP tasks and their deadlines: read from the newest ODP version,
    typed in when it cannot be read, and corrected by the assessors. Expects
    $assessments, $movements, $tasks, $totals, $counts, $state, $movement and $canEdit.
--}}
@php
    $filters = [
        'overdue' => ['Overdue', 'critical', 'alarm'],
        'soon' => ['Due within 30 days', 'warning', 'schedule'],
        'undated' => ['No date', 'serious', 'help'],
        'done' => ['Done', 'good', 'check_circle'],
    ];
@endphp

<div class="flex flex-wrap items-center gap-sm">
    {{-- Each count is also a filter; choosing it again shows every task. --}}
    @foreach ($filters as $key => [$label, $tone, $icon])
        <a href="{{ route('timelines', array_filter(['state' => $state === $key ? null : $key, 'movement' => $movement])) }}"
           @if ($state === $key) aria-current="true" @endif
           @class(['rounded-[0.875rem]', 'ring-2 ring-primary ring-offset-1' => $state === $key])>
            <x-chip :tone="$tone" :icon="$icon">{{ $label }} {{ $counts[$key] ?? 0 }}</x-chip>
        </a>
    @endforeach
    @if ($movements->count() > 1)
        <form method="GET" action="{{ route('timelines') }}" class="ml-auto flex items-center gap-xs text-[0.875rem]">
            @if ($state)<input type="hidden" name="state" value="{{ $state }}">@endif
            <label for="tl-movement" class="text-on-surface-variant">Movement</label>
            <select id="tl-movement" name="movement" x-data x-on:change="$el.form.submit()"
                    class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                <option value="">All {{ $movements->count() }}</option>
                @foreach ($movements as $m)
                    <option value="{{ $m->slug }}" @selected($movement === $m->slug)>{{ $m->name }}</option>
                @endforeach
            </select>
            <noscript><x-button variant="secondary">Show</x-button></noscript>
        </form>
    @endif
</div>

<details class="text-[0.875rem] rounded bg-surface-container-low px-sm py-xs">
    <summary class="cursor-pointer font-semibold text-primary py-1">Where these come from</summary>
    <div class="flex flex-col gap-xs pt-xs pb-sm">
        <p>Each task is read from the movement’s newest ODP: its TASKS and TO COMPLETE THE TASK BY DEADLINE columns, who is responsible, and whether it is marked completed. When a newer ODP is uploaded, its tasks are read again; a date someone corrected here is kept.</p>
        <p>A deadline written without a year (“End of June”, “End of first quarter”) takes the year from the ODP’s period of implementation, and is marked “Year guessed: check”. One with no date at all (“Ongoing”, “Annually”) shows as “No date”.</p>
        <p>An ODP saved as a Word or PDF file cannot be read. Type its tasks in with “Add a task”. Anyone who can edit can correct any task, set its date, or mark it done; every change is in the assessment’s audit trail.</p>
    </div>
</details>

@forelse ($assessments as $assessment)
    @php
        $list = $tasks[$assessment->id] ?? collect();
        $total = $totals[$assessment->id] ?? ['all' => 0, 'done' => 0];
        $editable = $canEdit[$assessment->id] ?? false;
        $read = $assessment->odpReadDocument;
        $late = $list->filter(fn ($t) => $t->state() === 'overdue')->count();
        $undated = $list->filter(fn ($t) => $t->state() === 'undated')->count();
        // One movement at a time: open when it is the one chosen, or when a filter has shortened the lists.
        $expanded = $movement !== null || $state !== null || $assessments->count() === 1;
    @endphp
    <details class="group bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg" @if ($expanded) open @endif>
        <summary class="cursor-pointer list-none p-md grid sm:grid-cols-[minmax(10rem,14rem)_1fr_auto] items-center gap-x-md gap-y-xs">
            <span class="flex items-center gap-xs min-w-0">
                <span class="material-symbols-outlined text-primary transition-transform group-open:rotate-90" aria-hidden="true">chevron_right</span>
                <span class="min-w-0">
                    <span class="block font-bold text-primary truncate">{{ $assessment->movement->name }}</span>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }}</span>
                </span>
            </span>
            @if ($total['all'] > 0)
                <x-bar wide :value="$total['done']" :max="$total['all']" tone="good" :label="$total['done'].' / '.$total['all'].' done'" />
            @else
                <span class="text-[0.8125rem] text-on-surface-variant">No tasks yet</span>
            @endif
            <span class="flex flex-wrap gap-xs">
                @if ($late)<x-chip tone="critical" icon="alarm">{{ $late }} overdue</x-chip>@endif
                @if ($undated)<x-chip tone="serious" icon="help">{{ $undated }} no date</x-chip>@endif
                @if ($assessment->odp_read_problem)<x-chip tone="serious" icon="info">Not read</x-chip>@endif
            </span>
        </summary>
        <div class="flex flex-col gap-md px-md pb-md">
        <p class="flex flex-wrap items-center gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant border-t border-surface-container pt-sm">
            <span>{{ $read ? 'Read from the ODP saved '.$read->created_at->asDay() : 'Typed in' }}</span>
            <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']) }}" class="text-primary underline">Open the ODP</a>
        </p>
        @if ($assessment->odp_read_problem)
            <x-callout tone="serious" icon="info" role="status">{{ $assessment->odp_read_problem }}</x-callout>
        @endif

        @if ($list->isEmpty())
            <x-empty-state icon="checklist">{{ $state ? 'No task matches this filter.' : 'No tasks yet.' }}</x-empty-state>
        @else
            <ul class="flex flex-col">
                @foreach ($list as $task)
                    @include('timelines._task', ['task' => $task, 'canEdit' => $editable])
                @endforeach
            </ul>
        @endif

        @if ($editable)
            <details class="text-[0.875rem] border-t border-surface-container pt-sm">
                <summary class="cursor-pointer font-semibold text-primary">Add a task</summary>
                <form method="POST" action="{{ route('timeline.tasks.store', $assessment) }}" class="flex flex-col gap-sm mt-sm">
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
                    <div class="grid sm:grid-cols-[1fr_auto] gap-sm">
                        <x-field label="Responsible">
                            <input name="responsible" maxlength="500" placeholder="Finance Committee" @class(\App\Support\Ui::control(false, 'sm'))>
                        </x-field>
                        <x-field label="Deadline">
                            <input type="date" name="due_on" @class(\App\Support\Ui::control(false, 'sm'))>
                        </x-field>
                    </div>
                    <x-button class="self-start">Add the task</x-button>
                </form>
            </details>
        @endif
        </div>
    </details>
@empty
    <x-empty-state icon="checklist">
        {{ auth()->user()->oversees() ? 'No movement has an ODP yet.' : 'None of the movements you assess has an ODP yet.' }}
        Tasks appear here once an ODP is uploaded on an assessment.
    </x-empty-state>
@endforelse
