@use('App\Support\Bands')
{{--
    One assessment, in the site's newer style (design standard of 10 Nov 2026): on top its
    score, gaps, due date (or when it was closed) and ODP validation as pills; once its ODP
    is approved, closing it (Stage 1 done, Baseline 1); its four gates as one line of steps; then its sections as tabs (form, report, ODP, timeline, audit trail), each in
    the same panels; and, tucked away, stopping work on it or deleting it.
--}}
<x-layouts.app :title="$assessment->movement->name.' — OHA '.$assessment->period_label"
               :subtitle="'Opened '.$assessment->opened_at->asDay().' by '.$assessment->opener->name">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            @if ($assessment->score)
                <span class="inline-flex items-baseline gap-[4px] rounded-full bg-surface-container-lowest border border-[#e7e5e0] px-[12px] py-[4px] text-[0.8125rem]">
                    <span class="font-bold tabular-nums">{{ number_format((float) $assessment->score->pct, 1) }}%</span>
                    <span class="text-on-surface-variant">{{ \App\Support\Viz::figure((float) $assessment->score->points_achieved) }} / {{ $assessment->score->points_available }}</span>
                </span>
                <x-status-pill :tone="Bands::tone($assessment->score->band->code)">{{ $assessment->score->band->label }}</x-status-pill>
                {{-- Once closed, the score is the newest baseline's: its history is a click away for those who follow it up. --}}
                @if ($assessment->isClosed() && Gate::allows('viewFollowUp', $assessment))
                    <a href="{{ route('baselines.show', $assessment) }}" class="text-[0.8125rem] font-semibold underline underline-offset-2">Baseline {{ $assessment->score->baseline }}</a>
                @endif
            @endif
            @if ($openGaps > 0 && Gate::allows('viewGaps', $assessment))
                <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']) }}#findings" class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <x-status-pill tone="critical" class="hover:brightness-95">{{ $openGaps }} missing from the form</x-status-pill>
                </a>
            @endif
            @if ($assessment->workItem)
                <x-due-note :days="$assessment->workItem->days_left" />
            @elseif ($assessment->closed_at)
                <x-status-pill tone="good">Closed {{ $assessment->closed_at->asDay() }} · Baseline 1</x-status-pill>
            @endif
            @if ($tabs->contains('odp'))
                <x-validation-chip :status="$artefacts['odp']->status" />
            @endif
        </div>
    </x-slot:actions>

    {{-- Stage 1 done: whoever may close it, once the ODP is approved and nothing waits for approval. --}}
    @can('close', $assessment)
        <x-action-banner id="close" title="The ODP is approved. Close the assessment to set Baseline 1 and start the follow-up.">
            <x-confirm-form :action="route('assessments.close', $assessment)" class="flex flex-wrap items-end gap-sm"
                            :confirm="'Close the '.$assessment->movement->name.' assessment? Its OHA form, report and ODP are frozen as approved, its scores become Baseline 1, and the follow-up begins. The Board Chairperson can still sign the ODP afterwards.'">
                <x-field label="Follow-up 1’s date (optional)" size="sm">
                    <input type="date" name="first_follow_up_on" @class(\App\Support\Ui::control(false, 'sm'))>
                </x-field>
                <x-button>Close the assessment</x-button>
            </x-confirm-form>
        </x-action-banner>
    @endcan

    @if ($milestones->isNotEmpty() && $canAudit)
        <x-panel class="!py-md">
            <x-gate-stepper :milestones="$milestones" :current="$assessment->workItem?->gate_code" />
        </x-panel>
    @endif

    <div class="flex flex-col gap-sm">
        <nav class="flex gap-lg overflow-x-auto border-b border-[#e4e1db]" aria-label="Assessment sections">
            @foreach ($tabs as $t)
                @php($state = in_array($t, ['form', 'report', 'odp'], true) ? $artefacts[$t]->status->effective_state : null)
                <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => $t]) }}" @if ($tab === $t) aria-current="page" @endif
                   @class(['flex items-center gap-[6px] whitespace-nowrap py-[10px] -mb-px border-b-2 text-[0.9375rem]',
                           'border-on-surface font-semibold text-on-surface' => $tab === $t,
                           'border-transparent text-on-surface-variant hover:text-on-surface' => $tab !== $t])>
                    {{ ['form' => '1. OHA form', 'report' => '2. Report', 'odp' => '3. ODP', 'timeline' => '4. Timeline', 'audit' => '5. Audit trail'][$t] }}
                    @if ($state === 'locked')
                        <span class="material-symbols-outlined text-[1rem]" aria-label="locked">lock</span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Who last changed this document, for those working on it: several assessors see each other's steps. --}}
        @if ($lastChange !== null && in_array($tab, ['form', 'report', 'odp'], true) && ($change = $lastChange($tab)))
            <p class="flex flex-wrap items-center gap-xs text-[0.8125rem] text-on-surface-variant">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">history</span>
                Last change: <strong class="text-on-surface">{{ $change->actor?->name ?? 'The platform' }}</strong>
                {{ str_replace(['.', '_'], [': ', ' '], $change->action) }},
                <span title="{{ $change->occurred_at->asStamp() }}">{{ $change->occurred_at->diffForHumans() }}</span>
                @if ($canAudit)
                    · <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'audit']) }}" class="font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">Audit trail</a>
                @endif
            </p>
        @endif
    </div>

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
                <details class="bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl p-lg shadow-[0_1px_2px_rgba(20,20,20,0.04)]" @if ($errors->has('reason') && old('_action') === 'hand-back') open @endif>
                    <summary class="cursor-pointer text-[0.9375rem] font-semibold text-on-surface">Stop working on this assessment</summary>
                    <form method="POST" action="{{ route('assessments.hand-back', $assessment) }}" class="flex flex-col gap-sm mt-sm">
                        @csrf
                        <input type="hidden" name="_action" value="hand-back">
                        <p class="text-[0.875rem] text-on-surface-variant">You will be taken off {{ $assessment->movement->name }}. The assessment stays as it is, and the Administrators are asked to assign someone to carry on.</p>
                        {{-- Not x-textarea: "reason" is also the delete form's box, so its old text and error belong to one form only. --}}
                        <x-field label="Why are you stopping? (required)">
                            <textarea name="reason" rows="2" required @class(\App\Support\Ui::control())>{{ old('_action') === 'hand-back' ? old('reason') : '' }}</textarea>
                        </x-field>
                        <x-button variant="secondary" class="self-start">Stop and hand it back</x-button>
                    </form>
                </details>
            @endcan
            @can('delete', $assessment)
                <details class="bg-surface-container-lowest border border-[#ecc9c4] rounded-2xl p-lg shadow-[0_1px_2px_rgba(20,20,20,0.04)]" @if (old('_action') === 'delete') open @endif>
                    <summary class="cursor-pointer text-[0.9375rem] font-semibold text-critical-ink">Delete this assessment</summary>
                    <form method="POST" action="{{ route('assessments.destroy', $assessment) }}" class="flex flex-col gap-sm mt-sm">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="_action" value="delete">
                        <p class="text-[0.875rem] text-on-surface-variant">Deletes the form, report, ODP, scores, signature, timeline and files. If this is the movement’s only assessment, it shows as not yet assessed. This cannot be undone; the audit trail keeps a record.</p>
                        <x-input name="confirm_name" :label="'Type '.$assessment->movement->name.' to confirm'" required />
                        <x-field label="Why is it being deleted? (required)">
                            <textarea name="reason" rows="2" required @class(\App\Support\Ui::control())>{{ old('_action') === 'delete' ? old('reason') : '' }}</textarea>
                        </x-field>
                        <x-button variant="danger" class="self-start">Delete the assessment</x-button>
                    </form>
                </details>
            @endcan
        </div>
    @endif
</x-layouts.app>
