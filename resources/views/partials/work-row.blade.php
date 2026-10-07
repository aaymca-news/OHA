{{--
    One item in a queue. Expects $entry = ['artefact', 'holder', 'days_left', 'actionable', 'blocked'] and optionally $group.
    A movement assigned to someone with no assessment under way comes as ['movement', 'artefact' => null, …].
--}}
@if (isset($entry['movement']))
    @php
        $m = $entry['movement'];
        $url = route('movements.show', $m);
        $last = $m->status?->last_assessed_on;
    @endphp
    <li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] {{ $entry['actionable'] ? 'border-outline-variant bg-surface-container-lowest' : 'border-surface-container bg-surface-container-low' }}">
        <span class="material-symbols-outlined text-primary" aria-hidden="true">flag</span>
        <div class="flex-1 min-w-56">
            <a href="{{ $url }}" class="text-[0.875rem] font-semibold text-primary underline">{{ $m->name }}</a>
            <p class="text-[0.8125rem] text-on-surface-variant">
                Assigned to you · {{ $last ? 'last assessed '.$last->format('M Y').' · no assessment under way' : 'not assessed yet' }}
            </p>
            @if ($entry['blocked'])
                <p class="text-[0.8125rem] flex items-center gap-xs mt-0.5"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">lock</span>{{ $entry['blocked'] }}</p>
            @endif
        </div>
        <x-chip icon="radio_button_unchecked">Not started</x-chip>
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
    <div class="flex-1 min-w-56">
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
