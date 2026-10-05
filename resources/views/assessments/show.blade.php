<x-layouts.app :title="$assessment->movement->name.' — OHA '.$assessment->period_label"
               :subtitle="'Opened '.$assessment->opened_at->format('j M Y').' by '.$assessment->opener->name">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            @if ($assessment->score)
                <x-chip tone="info" icon="scoreboard">{{ rtrim(rtrim(number_format((float) $assessment->score->points_achieved, 2), '0'), '.') }} / {{ $assessment->score->points_available }} · {{ number_format((float) $assessment->score->pct, 1) }}%</x-chip>
                <x-band-chip :band="$assessment->score->band" />
            @endif
            @if ($openGaps > 0 && Gate::allows('viewGaps', $assessment))
                <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']) }}#findings"><x-chip tone="serious" icon="playlist_remove">{{ $openGaps }} missing from the form</x-chip></a>
            @endif
            @if ($assessment->workItem)
                <x-due-chip :days="$assessment->workItem->days_left" />
            @else
                <x-chip tone="good" icon="verified_user">Stage 1 complete</x-chip>
            @endif
            @if ($tabs->contains('odp'))
                <span class="text-[0.8125rem] text-on-surface-variant">ODP</span> <x-validation-chip :status="$artefacts['odp']->status" />
            @endif
        </div>
    </x-slot:actions>

    @if ($milestones->isNotEmpty() && $canAudit)
        <x-gate-stepper :milestones="$milestones" :current="$assessment->workItem?->gate_code" />
    @endif

    <nav class="flex flex-wrap gap-xs border-b-[1.5px] border-outline-variant" aria-label="Assessment sections">
        @foreach ($tabs as $i => $t)
            @php($state = in_array($t, ['form', 'report', 'odp'], true) ? $artefacts[$t]->status->effective_state : null)
            <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => $t]) }}" @if ($tab === $t) aria-current="page" @endif
               @class(['px-md py-sm text-[0.875rem] -mb-[1.5px] border-b-[3px] flex items-center gap-xs',
                       'border-primary font-semibold text-primary' => $tab === $t,
                       'border-transparent text-on-surface-variant hover:text-primary' => $tab !== $t])>
                {{ ['form' => '1. OHA form', 'report' => '2. Report', 'odp' => '3. ODP', 'timeline' => '4. Timeline', 'audit' => '5. Audit trail'][$t] }}
                @if ($state === 'locked')
                    <span class="material-symbols-outlined text-[1rem]" aria-label="locked">lock</span>
                @endif
            </a>
        @endforeach
    </nav>

    @switch($tab)
        @case('form')
            @include('assessments.tabs.form', ['form' => $artefacts['form']])
            @break
        @case('report')
            @include('assessments.tabs.report', ['artefact' => $artefacts['report'], 'versions' => $versions['report']])
            @break
        @case('odp')
            @include('assessments.tabs.odp', ['artefact' => $artefacts['odp'], 'versions' => $versions['odp']])
            @break
        @case('timeline')
            @include('assessments.tabs.timeline')
            @break
        @case('audit')
            @include('assessments.tabs.audit')
            @break
    @endswitch

    {{-- Stop working on it (the assessor), or delete it (the Super Administrator). Tucked away: both are rare. --}}
    @if (Gate::allows('handBack', $assessment) || Gate::allows('delete', $assessment))
        <div class="grid md:grid-cols-2 gap-md items-start">
            @can('handBack', $assessment)
                <details class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md" @if ($errors->has('reason') && old('_action') === 'hand-back') open @endif>
                    <summary class="cursor-pointer text-[0.875rem] font-semibold text-primary">Stop working on this assessment</summary>
                    <form method="POST" action="{{ route('assessments.hand-back', $assessment) }}" class="flex flex-col gap-sm mt-sm">
                        @csrf
                        <input type="hidden" name="_action" value="hand-back">
                        <p class="text-[0.875rem] text-on-surface-variant">You will be taken off {{ $assessment->movement->name }}. The assessment stays as it is, and the Administrators are asked to assign someone to carry on.</p>
                        <label class="flex flex-col gap-xs text-[0.875rem]">
                            <span class="font-semibold">Why are you stopping? (required)</span>
                            <textarea name="reason" rows="2" required class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('_action') === 'hand-back' ? old('reason') : '' }}</textarea>
                        </label>
                        <x-button variant="secondary" class="self-start">Stop and hand it back</x-button>
                    </form>
                </details>
            @endcan
            @can('delete', $assessment)
                <details class="bg-surface-container-lowest border-[1.5px] border-band-critical rounded-lg p-md" @if (old('_action') === 'delete') open @endif>
                    <summary class="cursor-pointer text-[0.875rem] font-semibold text-critical-ink">Delete this assessment</summary>
                    <form method="POST" action="{{ route('assessments.destroy', $assessment) }}" class="flex flex-col gap-sm mt-sm">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="_action" value="delete">
                        <p class="text-[0.875rem] text-on-surface-variant">Deletes the form, report, ODP, scores, signature, timeline and files. If this is the movement’s only assessment, it shows as not yet assessed. This cannot be undone; the audit trail keeps a record.</p>
                        <x-input name="confirm_name" :label="'Type '.$assessment->movement->name.' to confirm'" required />
                        <label class="flex flex-col gap-xs text-[0.875rem]">
                            <span class="font-semibold">Why is it being deleted? (required)</span>
                            <textarea name="reason" rows="2" required class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('_action') === 'delete' ? old('reason') : '' }}</textarea>
                        </label>
                        <x-button variant="danger" class="self-start">Delete the assessment</x-button>
                    </form>
                </details>
            @endcan
        </div>
    @endif
</x-layouts.app>
