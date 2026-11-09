{{--
    One item in a queue. Expects $entry = ['artefact', 'holder', 'days_left', 'actionable', 'blocked'] and optionally $group.
    A movement assigned to someone with no assessment under way comes as ['movement', 'artefact' => null, …].
    A movement whose assessment has started comes as ['movement', 'artefact' (where it stands), 'started' => true, …].
    A movement whose assessment is done (ODP signed) comes as ['movement', 'done' => the assessment, …].
--}}
@if (isset($entry['started']))
    @php
        $assessment = $entry['artefact']->assessment;
        $url = route('assessments.show', ['assessment' => $assessment->id, 'tab' => $entry['artefact']->kind->value]);
    @endphp
    <li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] border-outline-variant bg-surface-container-lowest">
        <span class="material-symbols-outlined text-primary" aria-hidden="true">play_circle</span>
        <div class="flex-1 min-w-[min(14rem,100%)]">
            <a href="{{ $url }}" class="text-[0.875rem] font-semibold text-primary underline">{{ $entry['movement']->name }} · {{ $assessment->period_label }}</a>
            <p class="text-[0.8125rem] text-on-surface-variant">
                Started {{ $assessment->opened_at?->format('j M Y') }} · now: {{ $entry['artefact']->kind->label() }} · {{ $entry['holder'] }}
            </p>
        </div>
        <x-chip tone="info" icon="play_circle">Assessment started</x-chip>
        @if ($entry['days_left'] !== null)
            <x-due-chip :days="$entry['days_left']" />
        @endif
        <a href="{{ $url }}" class="px-md py-1.5 rounded border-[1.5px] border-primary text-primary text-[0.8125rem] font-semibold">Open</a>
    </li>
@elseif (isset($entry['done']))
    @php
        $m = $entry['movement'];
        $done = $entry['done'];
        $url = route('assessments.show', ['assessment' => $done->id, 'tab' => 'odp']);
        $next = $m->status?->next_assessment_on;
    @endphp
    <li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] border-outline-variant bg-surface-container-lowest">
        <span class="material-symbols-outlined text-primary" aria-hidden="true">verified</span>
        <div class="flex-1 min-w-[min(14rem,100%)]">
            <a href="{{ $url }}" class="text-[0.875rem] font-semibold text-primary underline">{{ $m->name }} · {{ $done->period_label }}</a>
            <p class="text-[0.8125rem] text-on-surface-variant">
                ODP signed by the board · follow-ups ongoing{{ $next ? ' · next assessment due '.$next->format('M Y') : '' }}
            </p>
        </div>
        <x-chip tone="good" icon="verified">Stage 1 done</x-chip>
        <x-chip tone="info" icon="autorenew">Follow-ups ongoing</x-chip>
        <a href="{{ $url }}" class="px-md py-1.5 rounded border-[1.5px] border-primary text-primary text-[0.8125rem] font-semibold">Open</a>
    </li>
@elseif (isset($entry['movement']))
    @php
        $m = $entry['movement'];
        $url = route('movements.show', $m);
        $last = $m->status?->last_assessed_on;
    @endphp
    <li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] {{ $entry['actionable'] ? 'border-outline-variant bg-surface-container-lowest' : 'border-surface-container bg-surface-container-low' }}">
        <span class="material-symbols-outlined text-primary" aria-hidden="true">flag</span>
        <div class="flex-1 min-w-[min(14rem,100%)]">
            <a href="{{ $url }}" class="text-[0.875rem] font-semibold text-primary underline">{{ $m->name }}</a>
            <p class="text-[0.8125rem] text-on-surface-variant">
                Assigned to you · {{ $last ? 'last assessed '.$last->format('M Y').' · no assessment under way' : 'not assessed yet' }}
            </p>
            @if ($entry['blocked'])
                <p class="text-[0.8125rem] flex items-center gap-xs mt-0.5"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">lock</span>{{ $entry['blocked'] }}</p>
            @endif
        </div>
        @if ($last)
            {{-- Assessed before, and the next assessment is now due. --}}
            <x-chip tone="warning" icon="event_repeat">Next assessment due</x-chip>
        @else
            <x-chip icon="radio_button_unchecked">Not started</x-chip>
        @endif
        @if ($entry['actionable'])
            <a href="{{ $url }}" class="px-md py-1.5 rounded bg-primary text-on-primary text-[0.8125rem] font-semibold">Start the assessment</a>
        @endif
    </li>
@else
@php
    $artefact = $entry['artefact'];
    $assessment = $artefact->assessment;
    $verb = ['approve' => 'Approve', 'sign' => 'Sign'][$group ?? ''] ?? 'Open';
    $url = route('assessments.show', ['assessment' => $assessment->id, 'tab' => $artefact->kind->value]);
@endphp
<li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] {{ $entry['actionable'] ? 'border-outline-variant bg-surface-container-lowest' : 'border-surface-container bg-surface-container-low' }}">
    <span class="material-symbols-outlined text-primary" aria-hidden="true">{{ ['form' => 'table_view', 'report' => 'description', 'odp' => 'checklist'][$artefact->kind->value] }}</span>
    <div class="flex-1 min-w-[min(14rem,100%)]">
        <a href="{{ $url }}" class="text-[0.875rem] font-semibold text-primary underline">{{ $assessment->movement->name }} · {{ $assessment->period_label }}</a>
        <p class="text-[0.8125rem] text-on-surface-variant">
            {{ $artefact->kind->label() }} · {{ $entry['holder'] }}
            @if ($artefact->submitter)
                · submitted by {{ $artefact->submitter->name }}
            @endif
        </p>
        @if ($entry['blocked'])
            <p class="text-[0.8125rem] flex items-center gap-xs mt-0.5"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">lock</span>{{ $entry['blocked'] }}</p>
        @endif
    </div>
    <x-state-chip :state="$artefact->state" />
    @if ($entry['days_left'] !== null)
        <x-due-chip :days="$entry['days_left']" />
    @endif
    @if ($entry['actionable'])
        <a href="{{ $url }}" class="px-md py-1.5 rounded bg-primary text-on-primary text-[0.8125rem] font-semibold">{{ $verb }}</a>
    @endif
</li>
@endif
