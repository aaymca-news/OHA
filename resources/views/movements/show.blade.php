@use('App\Support\Bands')
{{--
    One National Movement, in the site's newer style (design standard of 10 Nov 2026), built
    from the dashboards' pieces. AAYMCA staff see it from the list; its Board Chairperson and
    National General Secretary as "Our Movement". On top, its stage; then a slim banner when
    its assessor can start the next assessment; four key figures; its health by category
    beside its assessment cycle and its people; how its ODP is carried out; its scores over
    time; the movement at a glance (from its form); and its assessments, one row each.
--}}
@php
    $me = auth()->user();
    $paused = $movement->followUpPaused();
    $short = fn (string $name) => str_replace(' YMCA', '', $name);
    $label = 'text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant';
@endphp
<x-layouts.app :title="$movement->name" :subtitle="$movement->city.' · '.$movement->zone->name.' · '.$movement->membershipStatus->label">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            <x-status-pill :tone="$stage->tone()">{{ $stage->label() }}</x-status-pill>
            @can('update', $movement)
                <x-row-action :href="route('admin.movements.edit', $movement)">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit</span>Edit details
                </x-row-action>
            @endcan
        </div>
    </x-slot:actions>

    {{-- Only when its assessor can begin: one line, one button (to the form below). --}}
    @if ($canOpen && ! $underWay && ! ($lastDone && $followingUp))
        <x-action-banner :title="$status->latest_assessment_id ? $short($movement->name).'’s cycle is complete: it is ready for its next assessment' : $short($movement->name).' has not been assessed yet'"
                         href="#start" button="Start the assessment" />
    @endif

    {{-- Key figures --}}
    <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-[1.35fr_1fr_1fr_1fr]">
        <x-kpi label="Health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:badge>
                @if ($status->band && $status->pct !== null)
                    <x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill>
                @elseif ($underWay)
                    <x-status-pill tone="info">Assessment started</x-status-pill>
                @else
                    <x-status-pill>Not yet rated</x-status-pill>
                @endif
            </x-slot:badge>
            @if ($status->pct !== null)
                <x-band-scale :pct="$status->pct" class="mt-1" />
            @else
                Rated once an OHA form is approved
            @endif
        </x-kpi>

        <x-kpi label="Points" :value="$status->points_achieved !== null ? \App\Support\Viz::figure((float) $status->points_achieved) : '—'" :unit="$status->points_available ? ' / '.$status->points_available : null">
            {{ $status->last_assessed_on ? 'Assessed '.$status->last_assessed_on->asMonth() : 'No approved form yet' }}
        </x-kpi>

        {{-- No band-based date (decided 12 Nov 2026): the next assessment starts once the cycle is closed. --}}
        @php([$nextText, $nextTone] = $stage->nextAssessment($status->next_assessment_on))
        <x-kpi label="Next assessment" :value="$nextText">
            <x-slot:badge>
                @if ($paused)
                    <x-status-pill tone="warning">Paused</x-status-pill>
                @elseif ($nextTone === 'critical')
                    <x-status-pill tone="critical">Past its planned date</x-status-pill>
                @endif
            </x-slot:badge>
            @if ($paused)
                Paused until {{ $movement->follow_up_paused_until->asDay() }}
            @elseif ($status->latest_assessment_id)
                Once the cycle is closed, started by its assessors or AAYMCA
            @else
                {{ $movement->planned_assessment_label ? 'Planned '.$movement->planned_assessment_label : 'Not planned yet' }}
            @endif
        </x-kpi>

        <x-kpi label="Evidence library" :value="$evidenceCount" :unit="' '.\Illuminate\Support\Str::plural('document', $evidenceCount)">
            <a href="{{ route('evidence.index', $movement) }}" class="inline-flex items-center gap-[4px] font-semibold text-on-surface hover:underline">
                Open the library
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>
        </x-kpi>
    </section>

    {{-- Health by category | the cycle and its people --}}
    <div class="grid gap-md lg:grid-cols-[1.55fr_1fr] items-start">
        <div class="flex flex-col gap-md min-w-0">
            <x-panel title="Health by category" :subtitle="$latest ? (($latest->score?->baseline ?? 1) > 1 ? 'Baseline '.$latest->score->baseline.' of the '.$latest->period_label.' assessment, after follow-up '.($latest->score->baseline - 1) : 'From the approved OHA form, '.$latest->period_label).' · weakest first' : null">
                @if ($latest && $me->can('view', $latest))
                    <x-slot:actions><x-more-link :href="route('assessments.show', $latest)">Full scores</x-more-link></x-slot:actions>
                @endif
                @if ($latest)
                    <x-category-bars :rows="$rows" />
                @else
                    <x-empty-state icon="insights">{{ $movement->name }} has no approved OHA form yet, so it has no rating.</x-empty-state>
                @endif
            </x-panel>
            {{-- How its signed ODP is carried out --}}
            @if ($followUpProgress && $followUpProgress['summary']['total'] > 0)
                @php($s = $followUpProgress['summary'])
                <x-panel title="ODP follow-up" :subtitle="'The '.$lastDone->period_label.' ODP · as set at the last follow-up'">
                    <x-slot:actions><x-row-action :href="route('follow-up.show', $lastDone)">Open the follow-up</x-row-action></x-slot:actions>
                    <div class="flex flex-col gap-lg">
                        <div class="flex items-center gap-md">
                            <x-progress-ring :value="$s['done']" :max="max(1, $s['total'])" :size="84" />
                            <div class="flex flex-col gap-sm">
                                <p class="text-[0.9375rem] font-semibold">{{ $s['done'] }} of {{ $s['total'] }} tasks done</p>
                                <div class="flex flex-wrap gap-xs">
                                    @if ($s['in_progress'] > 0)<x-status-pill tone="warning">{{ $s['in_progress'] }} in progress</x-status-pill>@endif
                                    @if ($s['blocked'] > 0)<x-status-pill tone="critical">{{ $s['blocked'] }} blocked</x-status-pill>@else<x-status-pill tone="good">Nothing blocked</x-status-pill>@endif
                                </div>
                            </div>
                        </div>
                        @if (count($followUpProgress['priorities']) > 1)
                            <x-priority-bars :priorities="$followUpProgress['priorities']" />
                        @endif
                    </div>
                </x-panel>
            @endif
        </div>

        <div class="flex flex-col gap-md min-w-0">
            @if ($cycle)
                <x-panel title="Assessment cycle" :subtitle="$cycleOf->period_label.' assessment'">
                    <x-cycle-timeline :steps="$cycle" />
                </x-panel>
            @endif

            <x-panel title="People" subtitle="The movement’s own users see only this movement.">
                <ul class="flex flex-col">
                    @foreach ([
                        ['Board Chairperson', $chair, 'May sign the ODP (optional)', 'No Board Chairperson yet. Signing the ODP is optional.'],
                        ['National General Secretary', $generalSecretary, 'Supplies the evidence', 'No National General Secretary yet, so AAYMCA staff add the evidence.'],
                    ] as [$role, $person, $does, $none])
                        <li class="flex items-center gap-sm py-[10px] border-t border-[#f0eeea] first:border-0 first:pt-0">
                            @if ($person)
                                <x-avatar :user="$person" />
                                <span class="flex-1 min-w-0">
                                    <span class="block text-[0.875rem] font-semibold truncate">{{ $person->name }}</span>
                                    <span class="block text-[0.78125rem] text-on-surface-variant">{{ $role }} · {{ $does }}</span>
                                </span>
                            @else
                                <span class="flex-1 min-w-0">
                                    <span class="block text-[0.78125rem] font-semibold text-on-surface-variant">{{ $role }}</span>
                                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $none }}</span>
                                </span>
                                <x-status-pill tone="warning">To appoint</x-status-pill>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-panel>

            @if ($me->isSecretariat())
                @include('movements._assessors')
            @endif
        </div>
    </div>

    @if ($journey !== [])
        @include('baselines._journey', ['assessment' => $lastDone, 'journey' => $journey, 'link' => true])
    @endif

    @if ($history->count() > 1)
        @include('movements._score-history')
    @endif

    @if ($profile)
        @include('movements._profile')
    @endif

    {{-- Its assessments, one row each --}}
    <x-panel flush title="Assessments" id="assessments">
        @if ($underWay)
            {{-- One assessment at a time: no "Start new assessment" while this one runs. --}}
            <x-slot:actions>
                <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                    <x-status-pill tone="info">Assessment started</x-status-pill>
                    @can('view', $underWay)
                        <a href="{{ route('assessments.show', $underWay) }}" class="font-semibold hover:underline">{{ $underWay->period_label }}</a>
                    @else
                        <span class="font-semibold">{{ $underWay->period_label }}</span>
                    @endcan
                </span>
            </x-slot:actions>
        @elseif ($lastDone && $followingUp)
            {{-- Stage 1 done: the assessment is closed and its follow-ups are under way until the cycle is closed. --}}
            <x-slot:actions>
                <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                    <x-status-pill tone="good">Stage 1 done</x-status-pill>
                    <x-status-pill tone="warning">Follow-ups ongoing</x-status-pill>
                    @can('viewFollowUp', $lastDone)
                        <x-row-action primary :href="route('follow-up.show', $lastDone)">ODP follow-up</x-row-action>
                    @endcan
                </span>
            </x-slot:actions>
        @endif

        {{-- Starting one: the first, or the next once the previous cycle is closed (see MovementPolicy::openAssessment). --}}
        @if ($canOpen && ! $underWay)
            <div id="start" class="px-lg py-md border-b border-[#efede8] bg-[#fafaf8]">
                <p class="text-[0.875rem] font-semibold">Start the assessment</p>
                @include('movements._start-form')
            </div>
        @endif

        @if ($assessments->isEmpty())
            <div class="px-lg py-lg"><x-empty-state icon="fact_check">No assessments yet.</x-empty-state></div>
        @else
            <ul class="flex flex-col">
                @foreach ($assessments as $assessment)
                    @php($parts = $assessment->artefacts->keyBy(fn ($a) => $a->kind->value))
                    <li class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
                        <div class="flex-[999_1_9rem] min-w-0 flex flex-col gap-[2px]">
                            <a href="{{ route('assessments.show', $assessment) }}" class="text-[0.9375rem] font-semibold hover:underline">{{ $assessment->period_label }}</a>
                            @if ($assessment->score)
                                <span class="text-[0.8125rem] text-on-surface-variant">{{ \App\Support\Viz::pct($assessment->score->pct) }} · {{ $assessment->score->band->label }}</span>
                            @endif
                        </div>
                        <dl class="flex flex-wrap items-center gap-x-md gap-y-xs">
                            @foreach (['form', 'report', 'odp'] as $kind)
                                @php($display = \App\Enums\ArtefactState::display($parts[$kind]->status->effective_state))
                                <div class="flex items-center gap-[6px]">
                                    <dt class="text-[0.8125rem] text-on-surface-variant">{{ __('oha.artefact.'.$kind) }}</dt>
                                    <dd><x-status-pill :tone="$display['tone']">{{ $display['label'] }}</x-status-pill></dd>
                                    @if ($kind === 'odp')
                                        <dd><x-validation-chip :status="$parts[$kind]->status" /></dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                        {{-- A signed ODP: its follow-up (tasks, meetings, the overall review and carry-over). --}}
                        @if ($assessment->workItem === null && $me->can('viewFollowUp', $assessment))
                            <x-row-action :href="route('follow-up.show', $assessment)">Follow-up</x-row-action>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</x-layouts.app>
