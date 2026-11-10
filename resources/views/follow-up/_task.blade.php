{{--
    One ODP task on the Follow-up page: what it is, its deadline, where it stands, and,
    for those who may, reporting its progress or confirming it done. Several of these
    forms share field names on one page, so their controls are plain (no old() values).
    Expects $task, $canReport, $canConfirm, $evidence.
--}}
@php
    $progress = $task->progress;
    $waiting = $task->awaitsConfirmation();
    $overdue = $task->state() === 'overdue';
    $control = \App\Support\Ui::control(false, 'sm');
@endphp
<li id="task-{{ $task->id }}" class="flex flex-col gap-sm py-sm border-t border-surface-container first:border-0" x-data="{ open: false, back: false }">
    <div class="flex flex-wrap items-start gap-x-md gap-y-xs">
        <div class="flex-1 min-w-[min(16rem,100%)]">
            <p @class(['text-[0.875rem]', 'font-semibold' => ! $task->done])>
                @if ($task->ref)<span class="text-on-surface-variant font-normal">{{ $task->ref }} · </span>@endif{{ $task->task }}
            </p>
            <p class="text-[0.8125rem] text-on-surface-variant">
                {{ collect([$task->responsible ? 'By: '.$task->responsible : null, $task->due_on ? 'Due '.$task->due_on->asDay() : 'No date'])->filter()->join(' · ') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-xs">
            @if ($task->done)
                <x-chip tone="good" icon="verified">Done · confirmed</x-chip>
            @elseif ($waiting)
                <x-chip tone="warning" icon="hourglass_top">Done · waiting for confirmation</x-chip>
            @else
                <x-chip :tone="$progress->tone()" :icon="$progress->icon()">{{ $progress->label() }}</x-chip>
            @endif
            @if ($overdue)
                <x-chip tone="critical" icon="alarm">Overdue</x-chip>
            @endif
            @if ($task->carriedTo)
                <x-chip tone="info" icon="redo">Carried into {{ $task->carriedTo->period_label }}</x-chip>
            @elseif ($task->source === \App\Models\OdpTask::CARRIED)
                <x-chip tone="info" icon="history">Carried over{{ $task->carriedFrom?->assessment ? ' from '.$task->carriedFrom->assessment->period_label : '' }}</x-chip>
            @endif
        </div>
    </div>

    @if ($task->progress_note || $task->evidence || $task->reporter)
        <div class="text-[0.8125rem] flex flex-col gap-xs pl-sm border-l-2 border-outline-variant">
            @if ($task->progress_note)
                <p>“{{ $task->progress_note }}”</p>
            @endif
            @if ($task->evidence)
                <x-evidence-link :doc="$task->evidence" />
            @endif
            <p class="text-on-surface-variant">
                @if ($task->reporter)Reported by {{ $task->reporter->name }}, {{ $task->reported_at?->asDay() }}@endif
                @if ($task->done && $task->confirmer) · confirmed by {{ $task->confirmer->name }}, {{ $task->confirmed_at?->asDay() }}@endif
            </p>
        </div>
    @endif

    @if ($task->returned_reason)
        <x-callout tone="serious" icon="undo" title="Not confirmed yet">{{ $task->returned_reason }}</x-callout>
    @endif

    <div class="flex flex-wrap items-center gap-sm">
        @if ($canConfirm && $waiting)
            <form method="POST" action="{{ route('follow-up.confirm', $task) }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <x-button size="sm" icon="verified">Confirm done</x-button>
            </form>
            <x-button type="button" variant="link" x-on:click="back = ! back">Not done yet</x-button>
        @endif
        @if ($canReport)
            <x-button type="button" variant="outline" size="sm" icon="edit" x-on:click="open = ! open">Update progress</x-button>
        @endif
    </div>

    @if ($canConfirm && $waiting)
        <form method="POST" action="{{ route('follow-up.confirm', $task) }}" x-show="back" x-cloak class="flex flex-col gap-sm p-sm rounded bg-surface-container-low">
            @csrf
            <input type="hidden" name="confirmed" value="0">
            <x-field label="What is still missing?">
                <textarea name="reason" rows="2" required maxlength="2000" @class([...$control, 'w-full' => true])></textarea>
            </x-field>
            <x-button variant="secondary" size="sm" icon="undo" class="self-start">Send back as in progress</x-button>
        </form>
    @endif

    @if ($canReport)
        <form method="POST" action="{{ route('follow-up.progress', $task) }}" x-show="open" x-cloak class="flex flex-col gap-sm p-sm rounded bg-surface-container-low">
            @csrf
            <div class="flex flex-wrap items-end gap-sm">
                <x-field label="Where it stands" size="sm">
                    <select name="progress" @class($control)>
                        @foreach (\App\Enums\TaskProgress::options() as $value => $label)
                            <option value="{{ $value }}" @selected($progress->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Evidence (for Done)" size="sm" class="flex-1 min-w-[min(14rem,100%)]">
                    <select name="evidence_id" @class([...$control, 'w-full' => true])>
                        <option value="">None linked</option>
                        @foreach ($evidence as $id => $title)
                            <option value="{{ $id }}" @selected($task->evidence_id === $id)>{{ $title }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <x-field label="Note" hint="What was done, or what is in the way." size="sm">
                <textarea name="note" rows="2" maxlength="2000" @class([...$control, 'w-full' => true])>{{ $task->progress_note }}</textarea>
            </x-field>
            @if ($evidence === [])
                <p class="text-[0.8125rem] text-on-surface-variant">No evidence in the library yet: add it in Resources first, then link it here.</p>
            @endif
            <x-button size="sm" icon="save" class="self-start">Save</x-button>
        </form>
    @endif
</li>
