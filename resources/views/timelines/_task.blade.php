{{--
    One ODP task: what it is, who does it, its deadline and where it stands. With
    $canEdit, the assessors and Administrators can correct it there and then.
    Expects $task, and optionally $canEdit.
--}}
@php
    $state = $task->state();
    [$tone, $icon, $label] = [
        'done' => ['good', 'check_circle', 'Done'],
        'overdue' => ['critical', 'alarm', 'Overdue'],
        'soon' => ['warning', 'schedule', 'Due soon'],
        'upcoming' => ['neutral', 'event', 'Upcoming'],
        'undated' => ['serious', 'help', 'No date'],
    ][$state];
    $canEdit ??= false;
@endphp
<li class="flex flex-col gap-xs py-sm border-t border-surface-container first:border-0">
    <div class="flex flex-wrap items-start gap-x-md gap-y-xs">
        <div class="flex-1 min-w-[min(16rem,100%)]">
            <p @class(['text-[0.875rem]', 'line-through text-on-surface-variant' => $task->done, 'font-semibold' => ! $task->done])>
                @if ($task->ref)<span class="text-on-surface-variant font-normal no-underline">{{ $task->ref }} · </span>@endif{{ $task->task }}
            </p>
            <p class="text-[0.8125rem] text-on-surface-variant">
                {{ collect([$task->priority ? \Illuminate\Support\Str::title(mb_strtolower($task->priority)) : null, $task->responsible ? 'By: '.$task->responsible : null, $task->source === \App\Models\OdpTask::TYPED ? 'Typed in' : null])->filter()->join(' · ') }}
            </p>
        </div>
        <div class="flex flex-col items-start sm:items-end gap-xs text-[0.8125rem]">
            <x-chip :tone="$tone" :icon="$icon">{{ $label }}</x-chip>
            @if ($task->due_on)
                <span class="font-semibold tabular-nums">{{ $task->due_on->asDay() }}</span>
            @endif
            @if ($task->date_guessed)
                <span class="text-serious-ink" title="The ODP gave no year, so the year was taken from its period of implementation.">Year guessed: check</span>
            @endif
            @if ($task->deadline_text && ($task->due_on === null || $task->date_guessed || ! preg_match('/^\w{3}-\d{2}$|^\d{1,2}\/\d{1,2}\/\d{4}$/', $task->deadline_text)))
                <span class="text-on-surface-variant max-w-[16rem] sm:text-right">ODP says: “{{ \Illuminate\Support\Str::limit($task->deadline_text, 90) }}”</span>
            @endif
        </div>
    </div>

    @if ($canEdit)
        <details class="text-[0.875rem]" @if ($errors->any() && (int) old('odp_task') === $task->id) open @endif>
            <summary class="cursor-pointer text-primary underline self-start w-fit">{{ $task->due_on === null || $task->date_guessed ? 'Set the date' : 'Correct' }}</summary>
            <form method="POST" action="{{ route('timeline.tasks.update', $task) }}" class="flex flex-col gap-sm mt-sm p-sm rounded bg-surface-container-low">
                @csrf
                @method('PUT')
                <input type="hidden" name="odp_task" value="{{ $task->id }}">
                {{--
                    Plain boxes in x-field, not x-input/x-textarea: a refused save must not fill every
                    task's boxes with its values (x-field is given no name, so no error shows under every task either).
                --}}
                <x-field label="Task">
                    <textarea name="task" rows="2" required maxlength="4000" @class(\App\Support\Ui::control(false, 'sm'))>{{ $task->task }}</textarea>
                </x-field>
                <div class="grid sm:grid-cols-[1fr_auto_auto] gap-sm items-end">
                    <x-field label="Responsible">
                        <input name="responsible" value="{{ $task->responsible }}" maxlength="500" @class(\App\Support\Ui::control(false, 'sm'))>
                    </x-field>
                    <x-field label="Deadline">
                        <input type="date" name="due_on" value="{{ $task->due_on?->format('Y-m-d') }}" @class(\App\Support\Ui::control(false, 'sm'))>
                    </x-field>
                    <label class="flex items-center gap-xs pb-2"><input type="hidden" name="done" value="0"><input type="checkbox" name="done" value="1" @checked($task->done)> Done</label>
                </div>
                <x-button variant="secondary" class="self-start">Save the task</x-button>
            </form>
            <x-confirm-form :action="route('timeline.tasks.destroy', $task)" method="DELETE" :confirm="'Remove this task from the timeline?'" class="mt-xs">
                <x-button variant="link-danger" class="text-[0.8125rem]">Remove this task</x-button>
            </x-confirm-form>
        </details>
    @endif
</li>
