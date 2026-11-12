{{--
    One ODP task as a compact row (design standard of 10 Nov 2026): where it stands (its
    status pill, as set at the last follow-up), what it is and who does it, and, for the
    assessors and Administrators ($canEdit), a quiet button that opens the form to correct
    its wording or who is responsible. Tasks have no deadlines of their own, and where one
    stands is set at the follow-ups (decided 12 Nov 2026). Expects $task, and optionally $canEdit.
--}}
@php
    $status = $task->followUpStatus();
    $canEdit ??= false;
    // Only those who can correct it get a row that opens; for everyone else it is a plain row.
    [$wrap, $head] = $canEdit ? ['details', 'summary'] : ['div', 'div'];
@endphp
<li class="border-t border-[#f2f1ec] first:border-0">
    <{{ $wrap }} class="group/task" @if ($canEdit && $errors->any() && (int) old('odp_task') === $task->id) open @endif>
        <{{ $head }} @class(['oha-summary list-none flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md hover:bg-[#fafaf8]', 'cursor-pointer' => $canEdit])>
            <x-status-pill :tone="$status['tone']" class="min-w-[7.5rem]">{{ $status['label'] }}</x-status-pill>
            <span class="flex-[999_1_16rem] min-w-0 flex flex-col gap-[2px]">
                <span @class(['text-[0.9375rem]', 'line-through text-on-surface-variant' => $task->done, 'font-semibold' => ! $task->done])>
                    @if ($task->ref)<span class="text-on-surface-variant font-normal">{{ $task->ref }} · </span>@endif{{ $task->task }}
                </span>
                <span class="text-[0.8125rem] text-on-surface-variant">
                    {{ collect([$task->priority ? \Illuminate\Support\Str::title(mb_strtolower($task->priority)) : null, $task->responsible ? 'By: '.$task->responsible : null, $task->source === \App\Models\OdpTask::TYPED ? 'Typed in' : null, $task->source === \App\Models\OdpTask::CARRIED ? 'Carried over from an earlier ODP' : null])->filter()->join(' · ') }}
                </span>
            </span>
            @if ($canEdit)
                <span class="inline-flex items-center min-h-[40px] rounded-[10px] border border-[#e7e5e0] bg-surface-container-lowest px-[14px] text-[0.84375rem] font-semibold group-open/task:bg-surface-container-low">Correct</span>
            @endif
        </{{ $head }}>

        @if ($canEdit)
            <div class="mx-lg mb-md flex flex-col gap-sm rounded-xl border border-[#e7e5e0] bg-[#fafaf8] p-md text-[0.875rem]">
                <form method="POST" action="{{ route('timeline.tasks.update', $task) }}" class="flex flex-col gap-sm">
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
                    <x-field label="Responsible">
                        <input name="responsible" value="{{ $task->responsible }}" maxlength="500" @class(\App\Support\Ui::control(false, 'sm'))>
                    </x-field>
                    <p class="text-[0.8125rem] text-on-surface-variant">Where it stands is set at each follow-up meeting.</p>
                    <x-button size="sm" class="self-start">Save the task</x-button>
                </form>
                <x-confirm-form :action="route('timeline.tasks.destroy', $task)" method="DELETE" :confirm="'Remove this task from the timeline?'">
                    <x-button variant="link-danger" class="text-[0.8125rem]">Remove this task</x-button>
                </x-confirm-form>
            </div>
        @endif
    </{{ $wrap }}>
</li>
