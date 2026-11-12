{{--
    The three follow-ups once the assessment is closed (decided 12 Nov 2026, see
    FollowUpSchedule). Nothing happens by itself: the movement's assessors or an
    Administrator set each follow-up's date (as agreed with the movement), open it, record
    its meeting (what was said, where every task stood) and close it, which locks what it
    counted; closing one can date the next. Past its date and not closed, it shows as
    overdue. An Administrator can reopen the last one closed, with a reason. Expects
    $assessment, $meetings (FollowUpSchedule::for), $canRecord, $canRun, $canManageWindows,
    $evidenceCounts, $tasks.
--}}
@php
    $control = \App\Support\Ui::control(false, 'sm');
    $lastLocked = collect($meetings)->where('state', \App\Oha\FollowUpSchedule::LOCKED)->last()['number'] ?? null;
    // The follow-up under way: the first not closed.
    $current = collect($meetings)->first(fn ($m) => in_array($m['state'], [\App\Oha\FollowUpSchedule::OPEN, \App\Oha\FollowUpSchedule::UPCOMING], true))['number'] ?? null;
    $total = count($meetings);
@endphp
<x-panel title="Follow-ups" subtitle="Three, on dates agreed with the movement. Each is opened when it is time, held, then closed, which locks its meeting record, where each task stood and the evidence counted in it.">
    @if ($meetings === [])
        <x-empty-state icon="event">The follow-ups are planned once the assessment is closed.</x-empty-state>
    @else
        <ol class="flex flex-col">
            @foreach ($meetings as $slot)
                @php
                    $meeting = $slot['meeting'];
                    $window = $slot['window'];
                    $state = $slot['state'];
                    $open = $state === \App\Oha\FollowUpSchedule::OPEN;
                    $isCurrent = $slot['number'] === $current;
                    $settled = in_array($state, [\App\Oha\FollowUpSchedule::LOCKED, \App\Oha\FollowUpSchedule::SKIPPED], true);
                    $updates = $meeting?->tasks->pluck('update', 'odp_task_id') ?? collect();
                    $counted = (int) ($evidenceCounts[$slot['number']] ?? 0);
                    [$tone, $label] = match (true) {
                        $state === \App\Oha\FollowUpSchedule::LOCKED => ['good', 'Closed '.$window->locked_at->asDay()],
                        $state === \App\Oha\FollowUpSchedule::SKIPPED => ['neutral', 'Not held'],
                        $slot['overdue'] => ['critical', 'Overdue'],
                        $open => ['info', 'Open'],
                        default => ['neutral', 'Not open yet'],
                    };
                @endphp
                <li id="meeting-{{ $slot['number'] }}" class="flex flex-col gap-sm py-md border-t border-[#f2f1ec] first:border-0" x-data="{ record: false }">
                    <div class="flex flex-wrap items-center gap-x-md gap-y-sm">
                        <div class="flex-1 min-w-[min(14rem,100%)] flex flex-col gap-[2px]">
                            <p class="text-[0.9375rem]">
                                <span class="font-semibold">Follow-up {{ $slot['number'] }}</span>
                                <span class="text-on-surface-variant">· {{ $slot['due_on'] ? 'planned '.$slot['due_on']->asDay() : 'date not set yet' }}</span>
                            </p>
                            <p class="text-[0.8125rem] text-on-surface-variant">
                                {{ collect([
                                    $meeting ? 'Meeting held '.$meeting->held_on->asDay() : null,
                                    $counted > 0 ? $counted.' '.\Illuminate\Support\Str::plural('document', $counted).' '.($state === \App\Oha\FollowUpSchedule::LOCKED ? 'counted' : 'added for it') : null,
                                    $state === \App\Oha\FollowUpSchedule::LOCKED && $window->locker ? 'closed by '.$window->locker->name : null,
                                    $open && ! $meeting ? 'record its meeting, then close it' : null,
                                ])->filter()->join(' · ') ?: match (true) {
                                    $state === \App\Oha\FollowUpSchedule::SKIPPED => 'The cycle was closed without it.',
                                    $isCurrent => 'Evidence added now counts in this follow-up.',
                                    default => 'After follow-up '.($slot['number'] - 1).' is closed.',
                                } }}
                            </p>
                        </div>
                        <x-status-pill :tone="$tone">{{ $label }}</x-status-pill>
                        @if ($canRun && $isCurrent && ! $open)
                            <x-confirm-form :action="route('follow-up.open', $assessment)"
                                            :confirm="'Open follow-up '.$slot['number'].'? The General Secretary and the assessors are told, and the movement can add its evidence for it.'">
                                <x-button size="sm">Open follow-up {{ $slot['number'] }}</x-button>
                            </x-confirm-form>
                        @endif
                        @if ($canRecord && $isCurrent && $open)
                            <x-button type="button" :variant="$meeting ? 'secondary' : 'outline'" size="sm" x-on:click="record = ! record">{{ $meeting ? 'Correct the meeting' : 'Record the meeting' }}</x-button>
                        @endif
                        {{-- Re-scoring while it is open; once closed, the baseline it recorded. --}}
                        @if ($isCurrent && $open)
                            <x-row-action :href="route('baselines.show', $assessment).'#rescore'">{{ $canRun ? 'Re-score' : 'Scores' }} · Baseline {{ $slot['number'] + 1 }}</x-row-action>
                        @elseif ($state === \App\Oha\FollowUpSchedule::LOCKED)
                            <x-row-action :href="route('baselines.show', $assessment)">Baseline {{ $slot['number'] + 1 }}</x-row-action>
                        @endif
                    </div>

                    @if ($window?->reopen_reason)
                        <p class="text-[0.8125rem] text-on-surface-variant">Reopened {{ $window->reopened_at->asDay() }}{{ $window->reopener ? ' by '.$window->reopener->name : '' }}: “{{ $window->reopen_reason }}”</p>
                    @endif

                    {{-- Its date, as agreed with the movement: set or changed until it is closed. --}}
                    @if ($canRun && ! $settled)
                        <details class="text-[0.875rem]">
                            <summary class="cursor-pointer font-semibold w-fit text-on-surface-variant">{{ $slot['due_on'] ? 'Change the date' : 'Set the date' }}</summary>
                            <form method="POST" action="{{ route('follow-up.plan', ['assessment' => $assessment, 'number' => $slot['number']]) }}" class="flex flex-wrap items-end gap-sm mt-sm">
                                @csrf
                                @method('PUT')
                                <x-field label="Date agreed with the movement" size="sm">
                                    <input type="date" name="due_on" value="{{ $slot['due_on']?->toDateString() }}" @class($control)>
                                </x-field>
                                <x-button variant="secondary" size="sm">Save the date</x-button>
                            </form>
                        </details>
                    @endif

                    {{-- Closing it, once its meeting is recorded; the next follow-up's date can be set here too. --}}
                    @if ($canRun && $open && $meeting)
                        <x-confirm-form :action="route('follow-up.lock', ['assessment' => $assessment, 'number' => $slot['number']])"
                                        :confirm="'Close follow-up '.$slot['number'].'? Its meeting record, where each task stood and the evidence counted in it are locked, and the answers re-scored during it become Baseline '.($slot['number'] + 1).'. Only an Administrator can reopen it.'"
                                        class="flex flex-wrap items-end gap-sm p-md rounded-xl border border-[#e7e5e0] bg-[#fafaf8]">
                            @if ($slot['number'] < $total)
                                <x-field :label="'Follow-up '.($slot['number'] + 1).'’s date (optional)'" size="sm">
                                    <input type="date" name="next_due_on" value="{{ $meetings[$slot['number']]['due_on']?->toDateString() }}" @class($control)>
                                </x-field>
                            @endif
                            <x-button size="sm">Close follow-up {{ $slot['number'] }}</x-button>
                        </x-confirm-form>
                    @endif

                    @if ($meeting)
                        <details class="text-[0.875rem]">
                            <summary class="cursor-pointer font-semibold w-fit">What was agreed, and where each task stood</summary>
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
                                                    <x-status-pill tone="good">Done</x-status-pill>
                                                @else
                                                    <x-status-pill :tone="$row->progress->tone()">{{ $row->progress->label() }}</x-status-pill>
                                                @endif
                                            </td>
                                            <td class="text-[0.8125rem]">{{ $row->update ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </x-table>
                            </div>
                        </details>
                    @endif

                    {{-- An Administrator reopens the last follow-up closed, saying why. --}}
                    @if ($canManageWindows && $slot['number'] === $lastLocked)
                        <details class="text-[0.875rem]">
                            <summary class="cursor-pointer font-semibold w-fit text-on-surface-variant">Reopen follow-up {{ $slot['number'] }}…</summary>
                            <form method="POST" action="{{ route('follow-up.reopen', ['assessment' => $assessment, 'number' => $slot['number']]) }}" class="flex flex-col gap-sm mt-sm p-md rounded-xl border border-[#e7e5e0] bg-[#fafaf8]">
                                @csrf
                                <x-field label="Why it is reopened" size="sm">
                                    <textarea name="reason" rows="2" required maxlength="2000" @class([...$control, 'w-full' => true])></textarea>
                                </x-field>
                                <p class="text-[0.8125rem] text-on-surface-variant">The reason is kept. It can be corrected, then closed again.</p>
                                <x-button variant="secondary" size="sm" class="self-start">Reopen</x-button>
                            </form>
                        </details>
                    @endif

                    @if ($canRecord && $isCurrent && $open)
                        <form method="POST" action="{{ route('follow-up.meetings.store', $assessment) }}" x-show="record" x-cloak
                              class="flex flex-col gap-sm p-md rounded-xl border border-[#e7e5e0] bg-[#fafaf8]">
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
                            {{-- Every task together: where it stands as agreed at the meeting, and what was said about it. --}}
                            @if ($tasks->isNotEmpty())
                                <fieldset class="flex flex-col gap-xs">
                                    <legend class="mb-xs text-[0.875rem] font-semibold">Where each task stands</legend>
                                    <ul class="flex flex-col rounded-xl border border-[#e7e5e0] bg-surface-container-lowest">
                                        @foreach ($tasks as $task)
                                            @php($fixed = $task->isDropped() || $task->carried_to_assessment_id !== null)
                                            <li class="grid gap-x-sm gap-y-xs px-sm py-sm border-t border-[#f2f1ec] first:border-0 sm:grid-cols-[minmax(0,1fr)_10rem] items-start">
                                                <span class="text-[0.84375rem]">@if ($task->ref)<span class="text-on-surface-variant">{{ $task->ref }} · </span>@endif{{ \Illuminate\Support\Str::limit($task->task, 140) }}
                                                    @if ($task->progress_note)<span class="block text-[0.8125rem] text-on-surface-variant">Note: “{{ \Illuminate\Support\Str::limit($task->progress_note, 140) }}”</span>@endif
                                                </span>
                                                @if ($fixed)
                                                    <span class="text-[0.8125rem] text-on-surface-variant">{{ $task->followUpStatus()['label'] }}</span>
                                                @else
                                                    <select name="statuses[{{ $task->id }}]" aria-label="Where it stands: {{ \Illuminate\Support\Str::limit($task->task, 60) }}" @class($control)>
                                                        @foreach (\App\Enums\TaskProgress::cases() as $option)
                                                            <option value="{{ $option->value }}" @selected($task->progress === $option)>{{ $option->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                                <textarea name="updates[{{ $task->id }}]" rows="1" maxlength="2000" placeholder="What was said (optional)" aria-label="What was said: {{ \Illuminate\Support\Str::limit($task->task, 60) }}"
                                                          @class([...$control, 'w-full sm:col-span-2' => true])>{{ $updates[$task->id] ?? '' }}</textarea>
                                            </li>
                                        @endforeach
                                    </ul>
                                </fieldset>
                            @endif
                            <p class="text-[0.8125rem] text-on-surface-variant">Done counts from this meeting. Each task is then kept as it stands, so the meeting shows where things stood.</p>
                            <x-button size="sm" class="self-start">{{ $meeting ? 'Save the correction' : 'Record follow-up '.$slot['number'] }}</x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-panel>
