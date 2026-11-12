{{--
    The ODP during the follow-ups (decided 12 Nov 2026): while a follow-up is open, its
    assessors or an Administrator upload the revised ODP (changes made in Google Drive are
    taken in by themselves), and each revision lists what it changed in the plan: tasks
    added, removed (kept for the record), reworded, or with a new person responsible. No
    separate approval: closing the follow-up is the sign-off. Expects $assessment,
    $revisions (newest first) and $canRevise.
--}}
@php
    $closed = $assessment->isFollowUpClosed();
    $groups = [
        'added' => ['Added to the plan', 'good'],
        'removed' => ['Removed from the plan (kept for the record)', 'neutral'],
        'reworded' => ['Reworded', 'info'],
        'responsible' => ['New person responsible', 'info'],
    ];
@endphp
<x-panel id="odp-revisions" title="The ODP during the follow-ups"
         :subtitle="'While a follow-up is open, the revised ODP can be uploaded here; changes made in Google Drive are taken in by themselves. Tasks still in the plan keep where they stand, their notes and evidence.'">
    @if ($canRevise)
        <form method="POST" action="{{ route('follow-up.odp', $assessment) }}" enctype="multipart/form-data" class="flex flex-col gap-sm">
            @csrf
            <x-file-drop name="odp" accept=".xlsx,.docx,.pdf" label="Revised ODP" prompt="Drop the revised ODP here, or click to choose the file (.xlsx, .docx or .pdf)" />
            <x-field label="What changed (optional)" size="sm">
                <input type="text" name="note" maxlength="2000" placeholder="For example: tasks 3.2 and 5.1 added after the board meeting" @class(\App\Support\Ui::control(false, 'sm'))>
            </x-field>
            <p class="text-[0.8125rem] text-on-surface-variant">An Excel ODP’s tasks are read and matched against the plan. A Word or PDF ODP is kept, but its tasks cannot be read: correct them on Timelines.</p>
            <x-button size="sm" class="self-start">Save the revised ODP</x-button>
        </form>
    @elseif (! $closed)
        <p class="text-[0.875rem] text-on-surface-variant">The ODP is revised while a follow-up is open. Changes made in Google Drive meanwhile are noted, and taken in when the next follow-up is opened.</p>
    @endif

    @if ($revisions->isEmpty())
        <x-empty-state icon="history">No revision yet: the plan is the ODP approved with the assessment.</x-empty-state>
    @else
        <ol class="flex flex-col">
            @foreach ($revisions as $revision)
                <li class="flex flex-col gap-sm py-md border-t border-[#f2f1ec] first:border-0">
                    <div class="flex flex-wrap items-center gap-x-md gap-y-xs">
                        <p class="flex-1 min-w-[min(14rem,100%)] text-[0.875rem]">
                            <span class="font-semibold">Follow-up {{ $revision->follow_up_number }}</span>
                            <span class="text-on-surface-variant">· {{ $revision->created_at->asDay() }} · {{ $revision->fromDrive() ? 'from Google Drive'.($revision->edited_by_email ? ' ('.$revision->edited_by_email.')' : '') : 'uploaded by '.($revision->creator?->name ?? 'someone') }}</span>
                        </p>
                        <x-row-action :href="route('downloads.document', $revision)">Download</x-row-action>
                    </div>
                    @if ($revision->note)
                        <p class="text-[0.8125rem] text-on-surface-variant">“{{ $revision->note }}”</p>
                    @endif
                    @if (($revision->changes['kind'] ?? null) !== 'tasks')
                        <p class="text-[0.8125rem] text-on-surface-variant">Its tasks could not be read automatically{{ $revision->format->value === 'xlsx' ? '' : ' (a Word or PDF file)' }}: the plan’s tasks stay as they were.</p>
                    @elseif (collect($groups)->keys()->every(fn ($key) => ($revision->changes[$key] ?? []) === []))
                        <p class="text-[0.8125rem] text-on-surface-variant">No change to the tasks.</p>
                    @else
                        <div class="grid gap-sm sm:grid-cols-2">
                            @foreach ($groups as $key => [$heading, $tone])
                                @if (($revision->changes[$key] ?? []) !== [])
                                    <div class="flex flex-col gap-xs rounded-xl border border-[#efede8] p-sm">
                                        <x-status-pill :tone="$tone" class="self-start">{{ $heading }} · {{ count($revision->changes[$key]) }}</x-status-pill>
                                        <ul class="flex flex-col gap-[2px] text-[0.8125rem]">
                                            @foreach ($revision->changes[$key] as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-panel>
