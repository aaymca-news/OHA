{{--
    Scores and baselines of one closed assessment (decided 12 Nov 2026). Baseline 1 is the
    approved form; closing each follow-up records the next. While a follow-up is open, its
    answers are re-scored, from the re-filled Excel form or answer by answer here, by the
    form's own formulas: every point still open is listed with what earns it, so once each
    is met the score is the full 100%. "Not Applicable" scores 0, as on the Excel form.
    Earlier baselines are kept and can be viewed. Expects what BaselineController::show gives.
--}}
@php
    $movement = $assessment->movement;
    $shown = fn ($v) => $v === null || $v === '' ? 'blank' : (is_float($v) || is_int($v) ? \App\Support\Viz::figure($v, 6) : '“'.\Illuminate\Support\Str::limit((string) $v, 80).'”');
    $wording = fn (string $code) => ltrim($labels[$code] ?? \App\Oha\EvidenceQuestions::label($code) ?? '', '- ');
    $total = fn (?\App\Models\Baseline $b) => $b?->total;
    $current = $total($inForce);
    $start = $working['start'] ?? null;
    $startTotal = $total($start);
@endphp
<x-layouts.app title="Scores and baselines" :subtitle="$movement->name.' · '.$assessment->period_label">
    <x-button variant="link" icon="arrow_back" :href="route('follow-up.show', $assessment)" class="self-start">Back to the follow-up</x-button>

    @if ($inForce === null)
        <x-empty-state icon="leaderboard">No baseline yet: Baseline 1 is recorded when the assessment is closed with its approved form.</x-empty-state>
    @else
        {{-- At a glance --}}
        <section aria-label="At a glance" class="grid gap-md [grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))]">
            <x-kpi :label="'Score now · Baseline '.$inForce->number" :value="\App\Support\Viz::pct((float) $current?->pct)">
                <x-slot:badge><x-band-chip :band="$current?->band" /></x-slot:badge>
                {{ \App\Support\Viz::points((float) $current?->points_achieved, $current?->points_available) }} points · {{ $inForce->follow_up_number ? 'recorded when follow-up '.$inForce->follow_up_number.' was closed' : 'the approved form, when the assessment was closed' }}, {{ $inForce->set_at->asDay() }}
            </x-kpi>
            @if ($working)
                @php
                    $delta = $working['points'] - (float) $startTotal?->points_achieved;
                @endphp
                <x-kpi :label="'Being re-scored · Baseline '.$working['number']" :value="\App\Support\Viz::pct($working['pct'])">
                    <x-slot:badge><x-band-chip :band="$working['band']" /></x-slot:badge>
                    {{ \App\Support\Viz::points($working['points'], $working['max']) }} points ({{ $delta > 0 ? '+' : '' }}{{ \App\Support\Viz::figure($delta) }} since Baseline {{ $start->number }}) · recorded when follow-up {{ $working['followUp'] }} is closed
                </x-kpi>
                <x-kpi label="Points still open" :value="(string) array_sum($working['pointsOpen'])">
                    {{ count($working['changes']) }} {{ \Illuminate\Support\Str::plural('answer', count($working['changes'])) }} changed · {{ count($working['evidenceGaps']) }} “Yes” {{ \Illuminate\Support\Str::plural('answer', count($working['evidenceGaps'])) }} with no evidence yet
                </x-kpi>
            @else
                <x-panel class="flex flex-col gap-sm">
                    <span class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Re-scoring</span>
                    <span class="text-[0.9375rem]">
                        @if ($assessment->isFollowUpClosed())
                            The cycle is closed: its baselines are kept as they were.
                        @else
                            The answers are re-scored while a follow-up is open. Open the next follow-up on the follow-up page; closing it records Baseline {{ $inForce->number + 1 }}.
                        @endif
                    </span>
                </x-panel>
            @endif
        </section>

        {{-- The cycle so far, baseline by baseline --}}
        @include('baselines._journey', ['journey' => $journey])

        @if ($working)
            {{-- Two ways to re-score --}}
            <x-panel id="rescore" stack :title="'Re-scoring for Baseline '.$working['number']"
                     :subtitle="'Follow-up '.$working['followUp'].' is open. Change the answers either way below: they are scored by the OHA form’s own formulas, exactly as the Excel form scores them (“Not Applicable” scores 0). Closing the follow-up is the sign-off.'">
                @if ($canRescore)
                    <form method="POST" action="{{ route('baselines.upload', $assessment) }}" enctype="multipart/form-data" class="flex flex-col gap-sm">
                        @csrf
                        <x-file-drop name="form" accept=".xlsx" label="The re-filled OHA form (Excel)" prompt="Drop the movement’s re-filled OHA form here, or click to choose it (.xlsx)" />
                        <x-field label="What changed (optional)" size="sm">
                            <input type="text" name="note" maxlength="2000" placeholder="For example: re-filled with the General Secretary at the follow-up meeting" @class(\App\Support\Ui::control(false, 'sm'))>
                        </x-field>
                        <p class="text-[0.8125rem] text-on-surface-variant">Every answer is read from the file and replaces the answers being re-scored; each one that differs is listed below. Or change the answers one by one in the gaps further down.</p>
                        <x-button size="sm" class="self-start">Re-score from this form</x-button>
                    </form>
                @else
                    <p class="text-[0.875rem] text-on-surface-variant">The movement’s assessors, an Administrator or the Super Administrator re-score the answers.</p>
                @endif

                @if ($working['uploads']->isNotEmpty())
                    <ul class="flex flex-col gap-xs text-[0.8125rem] text-on-surface-variant">
                        @foreach ($working['uploads'] as $upload)
                            <li>{{ $upload->uploaded_at->asDay() }} · {{ $upload->original_name }} · uploaded by {{ $upload->uploader?->name ?? 'someone' }} · {{ count($upload->changed) }} {{ \Illuminate\Support\Str::plural('answer', count($upload->changed)) }} changed</li>
                        @endforeach
                    </ul>
                @endif
            </x-panel>

            {{-- What changed since the starting baseline --}}
            <x-panel id="changes" :title="'What changed since Baseline '.$start->number" :subtitle="'Each answer now different from Baseline '.$start->number.', with why, who and how. Points move only by the form’s scoring.'">
                @if ($working['changes'] === [])
                    <x-empty-state icon="history">No answer changed yet: Baseline {{ $working['number'] }} would be the same as Baseline {{ $start->number }}.</x-empty-state>
                @else
                    <ol class="flex flex-col">
                        @foreach ($working['changes'] as $code => $change)
                            <li class="flex flex-wrap items-start gap-x-md gap-y-xs py-sm border-t border-[#f2f1ec] first:border-0">
                                <div class="flex-1 min-w-[min(16rem,100%)] flex flex-col gap-[2px]">
                                    <p class="text-[0.875rem]"><span class="font-semibold">{{ str_replace('#2', ' (2nd)', $code) }}</span> <span class="text-on-surface-variant">{{ \Illuminate\Support\Str::limit($wording($code), 90) }}</span></p>
                                    <p class="text-[0.875rem]">{{ $shown($change['from']) }} → <span class="font-semibold">{{ $shown($change['to']) }}</span></p>
                                    <p class="text-[0.8125rem] text-on-surface-variant">“{{ $change['reason'] }}” · {{ $change['by_name'] }} · {{ \Illuminate\Support\Carbon::parse($change['at'])->asDay() }} · {{ $change['via'] === 'form' ? 'from the re-uploaded form'.(isset($change['file']) ? ' ('.$change['file'].')' : '') : 'changed on the site' }}</p>
                                </div>
                                @if ($canRescore)
                                    <x-confirm-form :action="route('baselines.undo', $assessment)" :confirm="'Set '.str_replace('#2', ' (2nd)', $code).' back to what it was in Baseline '.$start->number.'?'">
                                        <input type="hidden" name="code" value="{{ $code }}">
                                        <x-row-action type="submit">Undo</x-row-action>
                                    </x-confirm-form>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-panel>

            {{-- Every point still open, by category --}}
            <section aria-label="Points still open" class="flex flex-col gap-md">
                <div class="flex flex-col gap-xs">
                    <h2 class="text-[1.125rem] font-bold">Points still open</h2>
                    <p class="text-[0.875rem] text-on-surface-variant">Every scored question below its maximum, with what earns it on the form. Once each is met, the score is {{ $working['max'] }} of {{ $working['max'] }} (100%).</p>
                </div>
                @foreach ($working['rows'] as $category => $row)
                    @php
                        $items = $working['gaps'][$category] ?? [];
                        $earned = $working['earned'][$category] ?? [];
                    @endphp
                    <x-panel flush :title="$row['name']" :subtitle="$row['points'].' of '.$row['max'].' points'.($items !== [] ? ' · '.($working['pointsOpen'][$category] ?? 0).' still open' : ' · all earned')">
                        <ul class="flex flex-col">
                            @foreach ($items as $item)
                                <li id="{{ $item['code'] }}" class="flex flex-col gap-sm px-lg py-md border-t border-[#f2f1ec] first:border-0" x-data="{ change: false }">
                                    <div class="flex flex-wrap items-start gap-x-md gap-y-xs">
                                        <div class="flex-1 min-w-[min(16rem,100%)] flex flex-col gap-[2px]">
                                            <p class="text-[0.9375rem]"><span class="font-semibold">{{ $item['code'] === 'Q214' ? 'Q214–Q222' : $item['question']->displayCode() }}</span> {{ $item['code'] === 'Q214' ? 'Income by source' : $wording($item['code']) }}</p>
                                            <p class="text-[0.8125rem] text-on-surface-variant">{{ $item['earns'] }}</p>
                                            <p class="text-[0.8125rem]">
                                                Now:
                                                @foreach ($item['code'] === 'Q214' ? [] : $item['drivers'] as $driver)
                                                    <span class="font-semibold">{{ count($item['drivers']) > 1 || $driver !== $item['code'] ? str_replace('#2', ' (2nd)', $driver).' ' : '' }}{{ $shown($working['answers'][$driver] ?? null) }}</span>@if (! $loop->last) · @endif
                                                @endforeach
                                                @if ($item['code'] === 'Q214')
                                                    <span class="font-semibold">largest source {{ \App\Support\Viz::figure(collect(\App\Oha\FormDefinition::INCOME)->map(fn ($c) => \App\Oha\Answer::number($working['answers'][$c] ?? null))->filter(fn ($v) => $v !== null)->max() ?? 0) }}%</span>
                                                @endif
                                            </p>
                                        </div>
                                        <x-status-pill :tone="$item['points'] > 0 ? 'warning' : 'neutral'">{{ $item['points'] }} of {{ $item['max'] }}</x-status-pill>
                                        @if ($canRescore)
                                            <x-row-action x-on:click="change = ! change" x-bind:aria-expanded="change">Change</x-row-action>
                                        @endif
                                    </div>
                                    @if ($canRescore)
                                        <div x-show="change" x-cloak>
                                            @include('baselines._change-form', ['item' => $item, 'answers' => $working['answers']])
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($earned !== [])
                            <details @class(['px-lg py-md text-[0.875rem]', 'border-t border-[#f2f1ec]' => $items !== []])>
                                <summary class="cursor-pointer font-semibold w-fit text-on-surface-variant">Points earned · {{ count($earned) }} {{ \Illuminate\Support\Str::plural('question', count($earned)) }}</summary>
                                <ul class="flex flex-col mt-sm">
                                    @foreach ($earned as $item)
                                        <li id="{{ $item['code'] }}" class="flex flex-col gap-sm py-sm border-t border-[#f2f1ec] first:border-0" x-data="{ change: false }">
                                            <div class="flex flex-wrap items-start gap-x-md gap-y-xs">
                                                <p class="flex-1 min-w-[min(16rem,100%)] text-[0.875rem]">
                                                    <span class="font-semibold">{{ $item['code'] === 'Q214' ? 'Q214–Q222' : $item['question']->displayCode() }}</span>
                                                    {{ $item['code'] === 'Q214' ? 'Income by source' : \Illuminate\Support\Str::limit($wording($item['code']), 90) }}
                                                </p>
                                                @if (in_array($item['code'], $working['evidenceGaps'], true))
                                                    <x-status-pill tone="warning">No evidence yet</x-status-pill>
                                                @endif
                                                <x-status-pill tone="good">{{ $item['max'] }} of {{ $item['max'] }}</x-status-pill>
                                                @if ($canRescore)
                                                    <x-row-action x-on:click="change = ! change" x-bind:aria-expanded="change">Change</x-row-action>
                                                @endif
                                            </div>
                                            @if ($canRescore)
                                                <div x-show="change" x-cloak>
                                                    @include('baselines._change-form', ['item' => $item, 'answers' => $working['answers']])
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </x-panel>
                @endforeach
            </section>

            {{-- "Yes" answers with no evidence counted yet --}}
            <x-panel title="“Yes” answers with no evidence yet"
                     subtitle="They still score, as on the Excel form, but nothing proves them yet. Evidence added during this follow-up counts in it.">
                @if ($working['evidenceGaps'] === [])
                    <x-empty-state icon="verified">Every “Yes” that evidence can prove has evidence counted.</x-empty-state>
                @else
                    <ul class="flex flex-wrap gap-sm">
                        @foreach ($working['evidenceGaps'] as $code)
                            <li><x-status-pill tone="warning">{{ \App\Oha\EvidenceQuestions::shown($code) }} · {{ \App\Oha\EvidenceQuestions::label($code) }}</x-status-pill></li>
                        @endforeach
                    </ul>
                    <x-button variant="link" :href="route('evidence.index', $movement)" class="mt-sm">Add the evidence in Resources</x-button>
                @endif
            </x-panel>
        @endif

        {{-- Every baseline, newest first; earlier ones kept and viewable --}}
        <x-panel flush title="Baselines" subtitle="The newest in force is the movement’s score everywhere. Earlier ones are kept, never changed.">
            <x-table bare>
                <x-slot:head><tr><th>Baseline</th><th>Recorded</th><th>Score</th><th>Answers changed</th><th>ODP tasks done</th><th>No evidence yet</th><th><span class="sr-only">View</span></th></tr></x-slot:head>
                @foreach ($baselines->sortByDesc(fn ($b) => [$b->number, $b->id]) as $baseline)
                    <tr @class(['border-t border-surface-container align-top', 'bg-[#fafaf8]' => $viewed?->id === $baseline->id])>
                        <td>
                            <span class="font-semibold">Baseline {{ $baseline->number }}</span>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $baseline->follow_up_number ? 'after follow-up '.$baseline->follow_up_number : 'the assessment' }}</span>
                            @if ($baseline->isSuperseded())
                                <span class="block text-[0.8125rem] text-on-surface-variant">Set aside {{ $baseline->superseded_at->asDay() }}{{ $baseline->superseder ? ' by '.$baseline->superseder->name : '' }}: “{{ $baseline->superseded_reason }}”</span>
                            @endif
                        </td>
                        <td>{{ $baseline->set_at->asDay() }}<span class="block text-[0.8125rem] text-on-surface-variant">{{ $baseline->setter?->name }}</span></td>
                        <td>
                            {{ \App\Support\Viz::pct((float) $baseline->total?->pct) }} · {{ \App\Support\Viz::points((float) $baseline->total?->points_achieved, $baseline->total?->points_available) }}
                            <span class="block mt-xs"><x-band-chip :band="$baseline->total?->band" /></span>
                        </td>
                        <td>{{ $baseline->number === 1 ? '—' : count($baseline->changes ?? []) }}</td>
                        <td>{{ $baseline->tasks_total !== null ? $baseline->tasks_done.' of '.$baseline->tasks_total : '—' }}</td>
                        <td>{{ $baseline->evidence_gaps !== null ? count($baseline->evidence_gaps) : '—' }}</td>
                        <td class="text-right">
                            @if ($viewed?->id !== $baseline->id)
                                <x-row-action :href="route('baselines.show', ['assessment' => $assessment, 'baseline' => $baseline->id]).'#viewed'">View</x-row-action>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-panel>

        {{-- The baseline viewed --}}
        @if ($viewed)
            <x-panel id="viewed" stack :title="$viewed->label().($viewed->isSuperseded() ? ' (set aside)' : ($viewed->id === $inForce->id ? ' · in force' : ''))"
                     :subtitle="'Recorded '.$viewed->set_at->asDay().($viewed->setter ? ' by '.$viewed->setter->name : '').' · '.$viewed->evidence_count.' '.\Illuminate\Support\Str::plural('document', $viewed->evidence_count).' of evidence counted in it'">
                <x-category-bars :rows="$viewedRows" />
                @if (($viewed->changes ?? []) !== [])
                    <details class="text-[0.875rem]">
                        <summary class="cursor-pointer font-semibold w-fit">What changed since Baseline {{ $viewed->number - 1 }} · {{ count($viewed->changes) }}</summary>
                        <ul class="flex flex-col gap-xs mt-sm">
                            @foreach ($viewed->changes as $code => $change)
                                <li>
                                    <span class="font-semibold">{{ str_replace('#2', ' (2nd)', $code) }}</span>
                                    {{ $shown($change['from'] ?? null) }} → {{ $shown($change['to'] ?? null) }}
                                    <span class="text-on-surface-variant">· “{{ $change['reason'] ?? '' }}” · {{ $change['by_name'] ?? '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if (($viewed->evidence_gaps ?? []) !== [])
                    <div class="flex flex-col gap-xs">
                        <p class="text-[0.875rem] font-semibold">“Yes” answers with no evidence then</p>
                        <ul class="flex flex-wrap gap-sm">
                            @foreach ($viewed->evidence_gaps as $code)
                                <li><x-status-pill tone="warning">{{ \App\Oha\EvidenceQuestions::shown($code) }} · {{ \App\Oha\EvidenceQuestions::label($code) }}</x-status-pill></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-panel>
        @endif
    @endif
</x-layouts.app>
