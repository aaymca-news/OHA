{{--
    One ODP task on the follow-up page: a compact row (its status, the task, who and when,
    its evidence), and a side panel to work on it. The panel puts the decision first (an
    assessor confirming a reported Done), then where it stands, its evidence (added right
    here: it goes into Resources too), what has happened to it, and, out of the way,
    dropping it. Several of these forms share field names on one page, so their controls
    are plain (no old()). Expects $task, $status (OdpTask::followUpStatus), $canReport,
    $canConfirm, $canDrop, $evidence (the library: id => title).
--}}
@use('App\Enums\TaskProgress')
@use('App\Models\OdpTask')
@use('Illuminate\Support\Str')
@php
    $progress = $task->progress;
    $waiting = $task->awaitsConfirmation();
    $settled = $task->carried_to_assessment_id !== null || $task->isDropped();
    $report = $canReport && ! $settled;
    $confirm = $canConfirm && $waiting && ! $assessment->isFollowUpClosed();
    $drop = $canDrop && ! $task->done && $task->carried_to_assessment_id === null;
    $overdue = $status['key'] === 'overdue' && $status['label'] === 'Overdue';
    $control = \App\Support\Ui::control(false, 'sm');
    $when = $task->done && $task->confirmed_at ? 'Done '.$task->confirmed_at->asDay() : ($task->due_on ? 'Due '.$task->due_on->asDay() : 'No date set');
    $meta = collect([$task->responsible ? 'By '.$task->responsible : null, $when])->filter()->join(' · ');
    $dot = fn (TaskProgress $p) => match ($p) {
        TaskProgress::Done => 'bg-band-strong',
        TaskProgress::InProgress => 'bg-band-developing',
        TaskProgress::Blocked => 'bg-band-critical',
        default => 'bg-band-notstarted',
    };

    // What has happened to it, newest first.
    $activity = collect([
        $task->isDropped() ? ['icon' => 'block', 'text' => 'Dropped'.($task->dropper ? ' by '.$task->dropper->name : '').($task->dropped_reason ? ': “'.$task->dropped_reason.'”' : ''), 'at' => $task->dropped_at] : null,
        $task->carriedTo ? ['icon' => 'redo', 'text' => 'Carried into the '.$task->carriedTo->period_label.' assessment', 'at' => null] : null,
        $task->done && $task->confirmer ? ['icon' => 'verified', 'text' => 'Confirmed done by '.$task->confirmer->name, 'at' => $task->confirmed_at] : null,
        $task->returned_reason ? ['icon' => 'undo', 'text' => 'Sent back: “'.$task->returned_reason.'”', 'at' => null] : null,
        $task->reporter ? ['icon' => 'edit_note', 'text' => $task->reporter->name.' reported it '.Str::lower($progress->label()).($task->progress_note ? ': “'.$task->progress_note.'”' : ''), 'at' => $task->reported_at] : null,
        $task->source === OdpTask::CARRIED ? ['icon' => 'history', 'text' => 'Carried over'.($task->carriedFrom?->assessment ? ' from '.$task->carriedFrom->assessment->period_label : ''), 'at' => null] : null,
    ])->filter()->values();
@endphp
<li id="task-{{ $task->id }}" x-show="filter === 'all' || filter === '{{ $status['key'] }}'"
    class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]"
    x-data="{ open: false, kind: 'file', back: false, dropping: false, fileName: '' }"
    x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
    x-on:keydown.escape.window="open = false">
    <x-status-pill :tone="$status['tone']" class="min-w-[8.5rem]">{{ $status['label'] }}</x-status-pill>
    <div class="flex-[999_1_18rem] min-w-0 flex flex-col gap-[2px]">
        <span class="text-[0.9375rem] font-semibold">@if ($task->ref)<span class="text-on-surface-variant font-normal">{{ $task->ref }} · </span>@endif{{ $task->task }}</span>
        <span class="text-[0.8125rem] text-on-surface-variant">{{ $meta }}</span>
    </div>
    <span class="inline-flex items-center gap-xs text-[0.8125rem] text-on-surface-variant">
        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">attach_file</span>{{ $task->evidence ? '1 evidence' : 'No evidence' }}
    </span>
    <button type="button" x-on:click="open = true"
            class="min-h-[40px] rounded-[10px] border border-[#e7e5e0] bg-transparent px-[14px] text-[0.84375rem] font-semibold hover:bg-surface-container-low">
        {{ $report || $confirm || $drop ? 'Update' : 'Details' }}<span class="sr-only">: {{ $task->task }}</span>
    </button>

    {{-- The side panel --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-40 flex justify-end"
         x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-[rgba(28,27,31,0.36)] backdrop-blur-[2px]" x-on:click="open = false" aria-hidden="true"></div>
        <div role="dialog" aria-modal="true" aria-labelledby="task-{{ $task->id }}-title"
             x-show="open" x-effect="if (open) $nextTick(() => $refs.close.focus())"
             x-transition:enter="transition-transform duration-300 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition-transform duration-200 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="relative flex h-full w-[32rem] max-w-full flex-col bg-surface-container-lowest shadow-[-16px_0_48px_rgba(20,20,20,0.14)]">

            {{-- Header --}}
            <header class="flex flex-col gap-sm border-b border-[#efede8] px-lg pt-lg pb-md">
                <div class="flex items-center justify-between gap-sm">
                    <p class="text-[0.8125rem] text-on-surface-variant">
                        {{ Str::ucfirst(Str::lower($task->priority ?? 'ODP task')) }}@if ($task->ref)<span class="mx-[6px] text-[#c9c7c0]">/</span>Task {{ $task->ref }}@endif
                    </p>
                    <button type="button" x-ref="close" x-on:click="open = false" aria-label="Close"
                            class="grid size-[36px] place-items-center rounded-full text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface">
                        <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true">close</span>
                    </button>
                </div>
                <h3 id="task-{{ $task->id }}-title" class="text-[1.3125rem] font-bold leading-[1.3] tracking-[-0.01em]">{{ $task->task }}</h3>
                <div class="flex flex-wrap items-center gap-x-md gap-y-xs text-[0.8125rem] text-on-surface-variant">
                    <x-status-pill :tone="$status['tone']">{{ $status['label'] }}</x-status-pill>
                    @if ($task->responsible)
                        <span class="inline-flex items-center gap-[5px]"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">person</span>{{ $task->responsible }}</span>
                    @endif
                    <span @class(['inline-flex items-center gap-[5px]', 'font-semibold text-critical-ink' => $overdue])>
                        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">event</span>{{ $when }}
                    </span>
                </div>
            </header>

            {{-- Body --}}
            <div class="flex flex-1 flex-col gap-xl overflow-y-auto px-lg py-lg">

                {{-- The decision first: an assessor confirming a reported Done --}}
                @if ($confirm)
                    <section class="flex flex-col gap-md rounded-2xl border border-[#d6e2f5] bg-info-wash p-md">
                        <div class="flex items-start gap-sm">
                            <span class="material-symbols-outlined mt-[1px] text-[1.25rem] text-info-ink" aria-hidden="true">hourglass_top</span>
                            <div class="flex flex-col gap-[2px]">
                                <p class="text-[0.9375rem] font-semibold text-info-ink">Reported done: is it?</p>
                                <p class="text-[0.84375rem] text-info-ink/80">{{ $task->reporter?->name ?? 'The movement' }} reported this done{{ $task->reported_at ? ' on '.$task->reported_at->asDay() : '' }}. Confirm it, or send it back saying what is missing.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-sm">
                            <form method="POST" action="{{ route('follow-up.confirm', $task) }}">
                                @csrf
                                <input type="hidden" name="confirmed" value="1">
                                <x-button size="sm" icon="verified">Confirm done</x-button>
                            </form>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="back = ! back">Not yet</x-button>
                        </div>
                        <form method="POST" action="{{ route('follow-up.confirm', $task) }}" x-show="back" x-cloak class="flex flex-col gap-sm">
                            @csrf
                            <input type="hidden" name="confirmed" value="0">
                            <label class="flex flex-col gap-xs text-[0.84375rem] font-semibold text-info-ink">What is still missing?
                                <textarea name="reason" rows="2" required maxlength="2000" @class([...$control, 'w-full font-normal' => true])></textarea>
                            </label>
                            <x-button variant="secondary" size="sm" icon="undo" class="self-start">Send it back</x-button>
                        </form>
                    </section>
                @endif

                {{-- Where it stands --}}
                @if ($report)
                    <form id="progress-{{ $task->id }}" method="POST" action="{{ route('follow-up.progress', $task) }}" class="flex flex-col gap-md">
                        @csrf
                        <fieldset class="flex flex-col gap-sm">
                            <legend class="mb-sm text-[0.9375rem] font-semibold">Where it stands</legend>
                            <div class="grid grid-cols-2 gap-[4px] rounded-xl bg-surface-container-low p-[4px] sm:grid-cols-4">
                                @foreach (TaskProgress::cases() as $option)
                                    <label class="flex min-h-[44px] cursor-pointer items-center justify-center gap-[7px] rounded-[9px] px-sm text-[0.84375rem] font-semibold text-on-surface-variant hover:text-on-surface has-[:checked]:bg-surface-container-lowest has-[:checked]:text-on-surface has-[:checked]:shadow-[0_1px_3px_rgba(20,20,20,0.12)] has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-on-surface">
                                        <input type="radio" name="progress" value="{{ $option->value }}" @checked($progress === $option) class="sr-only">
                                        <span class="size-[8px] rounded-full {{ $dot($option) }}" aria-hidden="true"></span>{{ $option->label() }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-[0.8125rem] text-on-surface-variant">A “Done” from the General Secretary counts once an assessor confirms it.</p>
                        </fieldset>
                        <label class="flex flex-col gap-xs text-[0.875rem] font-semibold">Note
                            <textarea name="note" rows="3" maxlength="2000" placeholder="What was done, or what is in the way"
                                      @class([...$control, 'w-full font-normal' => true])>{{ $task->progress_note }}</textarea>
                        </label>
                        @if ($evidence !== [])
                            <details class="group text-[0.875rem]" @if ($task->evidence_id) open @endif>
                                <summary class="inline-flex cursor-pointer items-center gap-xs font-semibold text-on-surface-variant hover:text-on-surface">
                                    <span class="material-symbols-outlined text-[1.125rem] transition-transform group-open:rotate-90" aria-hidden="true">chevron_right</span>Link evidence already in Resources
                                </summary>
                                <select name="evidence_id" aria-label="Evidence already in Resources" @class([...$control, 'mt-sm w-full' => true])>
                                    <option value="">None linked</option>
                                    @foreach ($evidence as $id => $title)
                                        <option value="{{ $id }}" @selected($task->evidence_id === $id)>{{ $title }}</option>
                                    @endforeach
                                </select>
                            </details>
                        @endif
                    </form>
                @endif

                {{-- Evidence --}}
                <section class="flex flex-col gap-md" aria-labelledby="task-{{ $task->id }}-evidence">
                    <h4 id="task-{{ $task->id }}-evidence" class="text-[0.9375rem] font-semibold">Evidence</h4>
                    @if ($task->evidence)
                        <div class="flex items-center gap-md rounded-xl border border-[#e7e5e0] p-sm">
                            <span class="grid size-[40px] flex-none place-items-center rounded-[10px] bg-surface-container-low text-on-surface-variant">
                                <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true">{{ $task->evidence->url ? 'link' : 'description' }}</span>
                            </span>
                            <div class="min-w-0 flex-1 text-[0.875rem]"><x-evidence-link :doc="$task->evidence" /></div>
                            <span class="text-[0.75rem] font-semibold text-good-ink">In Resources</span>
                        </div>
                    @elseif (! $report)
                        <p class="text-[0.875rem] text-on-surface-variant">None linked.</p>
                    @endif

                    @if ($report)
                        <form method="POST" action="{{ route('follow-up.evidence', $task) }}" enctype="multipart/form-data" class="flex flex-col gap-sm">
                            @csrf
                            <div role="group" aria-label="Add a file or a link" class="inline-grid grid-cols-2 gap-[4px] self-start rounded-xl bg-surface-container-low p-[4px]">
                                <label class="flex min-h-[36px] cursor-pointer items-center justify-center gap-[6px] rounded-[9px] px-md text-[0.8125rem] font-semibold text-on-surface-variant has-[:checked]:bg-surface-container-lowest has-[:checked]:text-on-surface has-[:checked]:shadow-[0_1px_3px_rgba(20,20,20,0.12)]">
                                    <input type="radio" name="kind" value="file" x-model="kind" class="sr-only"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">upload_file</span>A file</label>
                                <label class="flex min-h-[36px] cursor-pointer items-center justify-center gap-[6px] rounded-[9px] px-md text-[0.8125rem] font-semibold text-on-surface-variant has-[:checked]:bg-surface-container-lowest has-[:checked]:text-on-surface has-[:checked]:shadow-[0_1px_3px_rgba(20,20,20,0.12)]">
                                    <input type="radio" name="kind" value="link" x-model="kind" class="sr-only"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">link</span>A link</label>
                            </div>
                            <label x-show="kind === 'file'"
                                   class="flex cursor-pointer flex-col items-center gap-[2px] rounded-xl border-2 border-dashed border-outline-variant px-md py-lg text-center hover:border-on-surface-variant hover:bg-[#fafaf8]">
                                <span class="material-symbols-outlined text-[1.75rem] text-on-surface-variant" aria-hidden="true" x-text="fileName ? 'task' : 'cloud_upload'">cloud_upload</span>
                                <span class="text-[0.875rem] font-semibold" x-text="fileName || 'Choose a file'">Choose a file</span>
                                <span class="text-[0.78125rem] text-on-surface-variant">PDF, Word, Excel or a photo · up to {{ \App\Support\UploadLimit::label() }}</span>
                                <input type="file" name="file" class="sr-only" x-bind:required="kind === 'file'" x-on:change="fileName = $event.target.files[0]?.name ?? ''">
                            </label>
                            <label x-show="kind === 'link'" x-cloak class="flex flex-col gap-xs text-[0.875rem] font-semibold">Link
                                <input type="url" name="url" placeholder="https://…" x-bind:required="kind === 'link'" @class([...$control, 'w-full font-normal' => true])>
                            </label>
                            <label class="flex flex-col gap-xs text-[0.875rem] font-semibold">What it is <span class="font-normal text-on-surface-variant">(optional)</span>
                                <input type="text" name="title" maxlength="200" placeholder="{{ Str::limit($task->task, 60) }}" @class([...$control, 'w-full font-normal' => true])>
                            </label>
                            <div class="flex flex-wrap items-center justify-between gap-sm">
                                <span class="inline-flex items-center gap-[5px] text-[0.8125rem] text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">folder_open</span>Saved to Resources › Evidence too
                                </span>
                                <x-button variant="secondary" size="sm" icon="add">Add evidence</x-button>
                            </div>
                        </form>
                    @endif
                </section>

                {{-- What has happened to it --}}
                @if ($activity->isNotEmpty())
                    <section class="flex flex-col gap-md" aria-labelledby="task-{{ $task->id }}-activity">
                        <h4 id="task-{{ $task->id }}-activity" class="text-[0.9375rem] font-semibold">Activity</h4>
                        <ol class="flex flex-col">
                            @foreach ($activity as $item)
                                <li class="relative flex gap-sm pb-md last:pb-0">
                                    @unless ($loop->last)<span class="absolute left-[13px] top-[28px] bottom-0 w-px bg-[#e7e5e0]" aria-hidden="true"></span>@endunless
                                    <span class="grid size-[28px] flex-none place-items-center rounded-full bg-surface-container-low text-on-surface-variant">
                                        <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $item['icon'] }}</span>
                                    </span>
                                    <div class="flex flex-col gap-[2px] pt-[3px] text-[0.84375rem]">
                                        <span>{{ $item['text'] }}</span>
                                        @if ($item['at'])<span class="text-[0.78125rem] text-on-surface-variant">{{ $item['at']->asDay() }}</span>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                {{-- Out of the way: dropping it, or bringing it back (assessors) --}}
                @if ($drop)
                    <section class="mt-auto border-t border-[#efede8] pt-md">
                        @if ($task->isDropped())
                            <form method="POST" action="{{ route('follow-up.drop', $task) }}" class="flex flex-wrap items-center justify-between gap-sm">
                                @csrf
                                <input type="hidden" name="undo" value="1">
                                <span class="text-[0.84375rem] text-on-surface-variant">Dropped from the follow-up.</span>
                                <x-button variant="secondary" size="sm" icon="undo">Bring it back</x-button>
                            </form>
                        @else
                            <button type="button" x-on:click="dropping = ! dropping" x-bind:aria-expanded="dropping"
                                    class="inline-flex items-center gap-xs text-[0.84375rem] font-semibold text-error hover:underline">
                                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">block</span>Drop this task
                            </button>
                            <form method="POST" action="{{ route('follow-up.drop', $task) }}" x-show="dropping" x-cloak class="mt-sm flex flex-col gap-sm rounded-xl bg-error-container/50 p-md">
                                @csrf
                                <p class="text-[0.8125rem] text-on-surface-variant">When it is no longer relevant or was replaced, instead of carrying it into the next assessment. The reason is kept with it.</p>
                                <label class="flex flex-col gap-xs text-[0.84375rem] font-semibold">Why it is dropped
                                    <textarea name="reason" rows="2" required maxlength="2000" @class([...$control, 'w-full font-normal' => true])></textarea>
                                </label>
                                <x-button variant="danger" size="sm" icon="block" class="self-start">Drop it</x-button>
                            </form>
                        @endif
                    </section>
                @endif
            </div>

            {{-- Footer: saving where it stands --}}
            @if ($report)
                <footer class="flex items-center justify-end gap-sm border-t border-[#efede8] bg-surface-container-lowest px-lg py-md">
                    <x-button type="button" variant="secondary" x-on:click="open = false">Cancel</x-button>
                    <x-button type="submit" form="progress-{{ $task->id }}" icon="check">Save</x-button>
                </footer>
            @endif
        </div>
    </div>
</li>
