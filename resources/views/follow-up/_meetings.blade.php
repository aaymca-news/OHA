{{--
    The follow-up meetings on a signed ODP (3 a year from the signing): each one's due
    date, and once held, what was said and where every task stood. Assessors record them.
    Expects $assessment, $meetings (FollowUpSchedule::for), $canRecord, $tasks.
--}}
@php
    $control = \App\Support\Ui::control(false, 'sm');
@endphp
<x-card title="Follow-up meetings" subtitle="Three a year, as in the ODP template: about every four months from the signing. Each records what was agreed and where every task stood.">
    @if ($meetings === [])
        <x-empty-state icon="event">The follow-up meetings are planned once the ODP is signed.</x-empty-state>
    @else
        <ol class="flex flex-col">
            @foreach ($meetings as $slot)
                @php
                    $meeting = $slot['meeting'];
                    $due = $slot['due_on'];
                    $updates = $meeting?->tasks->pluck('update', 'odp_task_id') ?? collect();
                @endphp
                <li id="meeting-{{ $slot['number'] }}" class="flex flex-col gap-sm py-sm border-t border-surface-container first:border-0" x-data="{ record: false }">
                    <div class="flex flex-wrap items-center gap-sm">
                        <p class="flex-1 min-w-[min(14rem,100%)] text-[0.875rem]">
                            <span class="font-semibold">Follow-up {{ $slot['number'] }}</span>
                            <span class="text-on-surface-variant">· due {{ $due->asDay() }}</span>
                        </p>
                        @if ($meeting)
                            <x-chip tone="good" icon="event_available">Held {{ $meeting->held_on->asDay() }}</x-chip>
                        @elseif ($due->isPast())
                            <x-chip tone="critical" icon="alarm">Overdue</x-chip>
                        @elseif ($due->lte(today()->addDays(config('oha.due_soon_days.odp_tasks'))))
                            <x-chip tone="warning" icon="schedule">Due soon</x-chip>
                        @else
                            <x-chip icon="event">Upcoming</x-chip>
                        @endif
                        @if ($canRecord)
                            <x-button type="button" :variant="$meeting ? 'link' : 'outline'" size="sm" icon="edit_note" x-on:click="record = ! record">{{ $meeting ? 'Correct' : 'Record this meeting' }}</x-button>
                        @endif
                    </div>

                    @if ($meeting)
                        <details class="text-[0.875rem]">
                            <summary class="cursor-pointer text-primary underline w-fit">What was agreed, and where each task stood</summary>
                            <div class="flex flex-col gap-sm mt-sm">
                                <p class="text-[0.8125rem] text-on-surface-variant">
                                    Recorded by {{ $meeting->recorder?->name ?? 'an assessor' }}{{ $meeting->attendees ? ' · Present: '.$meeting->attendees : '' }}
                                </p>
                                <p class="whitespace-pre-line">{{ $meeting->summary }}</p>
                                <x-table bare>
                                    <x-slot:head><tr><th>Task</th><th>Where it stood</th><th>Update</th></tr></x-slot:head>
                                    @foreach ($meeting->tasks as $row)
                                        <tr class="border-t border-surface-container align-top">
                                            <td>@if ($row->ref)<span class="text-on-surface-variant">{{ $row->ref }} · </span>@endif{{ $row->task }}</td>
                                            <td>
                                                @if ($row->done)
                                                    <x-chip tone="good" icon="verified">Done</x-chip>
                                                @else
                                                    <x-chip :tone="$row->progress->tone()" :icon="$row->progress->icon()">{{ $row->progress->label() }}</x-chip>
                                                @endif
                                            </td>
                                            <td class="text-[0.8125rem]">{{ $row->update ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </x-table>
                            </div>
                        </details>
                    @endif

                    @if ($canRecord)
                        <form method="POST" action="{{ route('follow-up.meetings.store', $assessment) }}" x-show="record" x-cloak
                              class="flex flex-col gap-sm p-sm rounded bg-surface-container-low">
                            @csrf
                            <input type="hidden" name="number" value="{{ $slot['number'] }}">
                            <div class="flex flex-wrap items-end gap-sm">
                                <x-field label="Held on" size="sm">
                                    <input type="date" name="held_on" required max="{{ today()->toDateString() }}"
                                           value="{{ $meeting?->held_on->toDateString() ?? today()->toDateString() }}" @class($control)>
                                </x-field>
                                <x-field label="Present" size="sm" class="flex-1 min-w-[min(16rem,100%)]">
                                    <input type="text" name="attendees" maxlength="1000" value="{{ $meeting?->attendees }}" placeholder="Who attended" @class([...$control, 'w-full' => true])>
                                </x-field>
                            </div>
                            <x-field label="What was discussed and agreed" size="sm">
                                <textarea name="summary" rows="4" required maxlength="10000" @class([...$control, 'w-full' => true])>{{ $meeting?->summary }}</textarea>
                            </x-field>
                            @if ($tasks->isNotEmpty())
                                <details class="text-[0.8125rem]">
                                    <summary class="cursor-pointer text-primary underline w-fit">An update on each task (optional)</summary>
                                    <div class="flex flex-col gap-sm mt-sm">
                                        @foreach ($tasks as $task)
                                            <x-field :label="($task->ref ? $task->ref.' · ' : '').\Illuminate\Support\Str::limit($task->task, 110)" size="sm">
                                                <textarea name="updates[{{ $task->id }}]" rows="1" maxlength="2000" @class([...$control, 'w-full' => true])>{{ $updates[$task->id] ?? '' }}</textarea>
                                            </x-field>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                            <p class="text-[0.8125rem] text-on-surface-variant">Each task is kept as it stands now (Done, In progress…). Update the tasks’ progress first if it changed at the meeting.</p>
                            <x-button size="sm" icon="save" class="self-start">{{ $meeting ? 'Save the correction' : 'Record follow-up '.$slot['number'] }}</x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-card>
