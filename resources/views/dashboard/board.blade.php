@use('App\Support\Viz')
@use('App\Support\Bands')
@use('App\Enums\Stage')
{{--
    A National Movement's own dashboard, for its Board Chairperson and National General
    Secretary (design standard of 10 Nov 2026), laid out like AAYMCA's: a slim banner only
    when something waits on this person, then four key figures, the health by category and
    the assessment cycle, the ODP by priority and its tasks still open (as set at the last
    follow-up: tasks have no deadlines), and the movement in numbers, its contacts and its
    documents. Built from the shared components (x-action-banner, x-kpi, x-category-bars,
    x-cycle-timeline, x-priority-bars, x-task-line).
--}}
@php
    $me = auth()->user();
    $holder = $current?->workItem;
    $stage = Stage::of($cycleOf ?? $latest);
    $firstName = \Illuminate\Support\Str::before($me->name, ' ');
    $open = $tasks->reject(fn ($t) => $t->isSettled());
    // Still open, blocked first, then in progress, then not started.
    $stillOpen = $open->sortBy(fn ($t) => match ($t->progress) {
        \App\Enums\TaskProgress::Blocked => 0,
        \App\Enums\TaskProgress::InProgress => 1,
        default => 2,
    })->take(5);
@endphp
<x-layouts.app :title="$status->name" :subtitle="'Welcome back, '.$firstName.' · '.$me->role->label()">
    <x-slot:actions>
        <x-status-pill :tone="$stage->tone()" class="self-center">{{ $stage->label() }}</x-status-pill>
    </x-slot:actions>

    {{-- Only when something waits on this person: one line, one button. Otherwise straight to the figures. --}}
    @if ($action)
        <x-action-banner :title="$action['title']" :href="$action['href']" :button="$action['button']" />
    @endif

    {{-- Key figures --}}
    <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-[1.35fr_1fr_1fr_1fr]">
        <x-kpi label="Health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:badge>
                @if ($status->band && $status->pct !== null)
                    <x-status-pill :tone="Bands::tone($status->band_code)">{{ $status->band->label }}</x-status-pill>
                @elseif ($holder)
                    <x-status-pill tone="info">Assessment started</x-status-pill>
                @else
                    <x-status-pill>Not yet assessed</x-status-pill>
                @endif
            </x-slot:badge>
            @if ($status->pct !== null)
                <x-band-scale :pct="$status->pct" class="mt-1" />
            @else
                Your score appears once your OHA form is approved.
            @endif
        </x-kpi>

        <x-kpi label="ODP done" :value="$odp ? $odp['done'] : '—'" :unit="$odp ? ' / '.$odp['total'].' '.\Illuminate\Support\Str::plural('task', $odp['total']) : null">
            @if ($odp && $odp['total'] > 0)
                <x-slot:visual><x-progress-ring :value="$odp['done']" :max="$odp['total']" :size="72" /></x-slot:visual>
                As set at your last follow-up
            @else
                {{ $odpAssessment ? 'Its tasks are not entered yet' : 'No approved ODP yet' }}
            @endif
        </x-kpi>

        <x-kpi label="Blocked" :value="$odp ? $odp['blocked'] : '—'" :tone="($odp['blocked'] ?? 0) > 0 ? 'critical' : null">
            @if (! $odp)
                Shown once your ODP is approved
            @elseif ($odp['blocked'] > 0)
                of {{ $open->count() }} {{ \Illuminate\Support\Str::plural('task', $open->count()) }} still open
            @else
                Nothing blocked{{ $open->isNotEmpty() ? ' · '.$open->count().' still open' : '' }}
            @endif
        </x-kpi>

        {{-- No band-based date (decided 12 Nov 2026): it starts once the cycle is closed. --}}
        <x-kpi label="Next assessment" :value="$stage->nextAssessment($status->next_assessment_on)[0]">
            @if ($stage->nextAssessment($status->next_assessment_on)[1] === 'critical')
                <span class="text-critical-ink font-semibold">Past its planned date</span>
            @else
                {{ $latest ? 'Last: '.$latest->period_label : 'Not yet assessed' }}
            @endif
        </x-kpi>
    </section>

    {{-- Health by category | the assessment cycle --}}
    <div class="grid gap-md lg:grid-cols-[1.55fr_1fr] items-start">
        <x-panel title="Health by category" :subtitle="$latest ? 'From your approved OHA form, '.$latest->period_label.' · weakest first' : null">
            @if ($latest && $me->can('view', $latest))
                <x-slot:actions><x-more-link :href="route('assessments.show', $latest)">Full scores</x-more-link></x-slot:actions>
            @endif
            @if ($latest)
                <x-category-bars :rows="$rows" />
            @else
                <x-empty-state icon="insights">Your scores appear here once your OHA form is approved.</x-empty-state>
            @endif
        </x-panel>

        <x-panel title="Your assessment cycle" :subtitle="$cycleOf ? $cycleOf->period_label.' assessment' : 'No assessment yet'">
            <x-cycle-timeline :steps="$cycle" />
        </x-panel>
    </div>

    {{-- The ODP --}}
    <x-panel title="Your ODP" :subtitle="$odp && $odp['total'] > 0 ? $odp['done'].' of '.$odp['total'].' tasks done · '.$odpAssessment->period_label.' · across '.count($priorities).' '.\Illuminate\Support\Str::plural('priority', count($priorities)) : ($odpAssessment ? $odpAssessment->period_label : null)">
        @if ($canFollowUp)
            <x-slot:actions>
                <a href="{{ route('follow-up.show', $odpAssessment) }}" class="inline-flex items-center gap-sm min-h-10 px-[14px] rounded-[10px] border border-[#d9d7d1] bg-surface-container-lowest text-[0.84375rem] font-semibold text-on-surface hover:bg-[#f2f1ee]">
                    {{ $canNote ? 'Add notes or evidence' : 'Open the follow-up' }}
                </a>
            </x-slot:actions>
        @endif
        @if (! $odpAssessment)
            <x-empty-state icon="checklist">Your ODP’s tasks appear here once it is approved.</x-empty-state>
        @elseif ($tasks->isEmpty())
            <x-empty-state icon="checklist">Your ODP’s tasks have not been entered yet.</x-empty-state>
        @else
            <div class="grid gap-x-xl gap-y-lg lg:grid-cols-2">
                <x-priority-bars :priorities="$priorities" />
                <div class="flex flex-col">
                    <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Still open</p>
                    @if ($stillOpen->isEmpty())
                        <p class="mt-sm"><x-status-pill tone="good">Every task is done</x-status-pill></p>
                    @else
                        <ul class="mt-1">
                            @foreach ($stillOpen as $task)
                                <x-task-line :task="$task" />
                            @endforeach
                        </ul>
                    @endif
                    @if ($canFollowUp)
                        <x-more-link :href="route('follow-up.show', $odpAssessment)" class="self-start mt-sm -ml-[10px]">All {{ $tasks->count() }} tasks</x-more-link>
                    @elseif ($tasks->count() > $stillOpen->count())
                        <details class="text-[0.875rem] mt-sm">
                            <summary class="cursor-pointer font-semibold w-fit">All {{ $tasks->count() }} tasks</summary>
                            <ul class="flex flex-col mt-xs">
                                @foreach ($tasks as $task)
                                    <x-task-line :task="$task" />
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </div>
        @endif
    </x-panel>

    {{-- In numbers · contacts · documents --}}
    <div class="grid gap-md lg:grid-cols-[1.1fr_1fr_1fr] items-stretch">
        <x-panel title="In numbers" :subtitle="$profile ? 'From your approved OHA form' : null">
            @if ($profile)
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-sm gap-y-[18px]">
                    @foreach ([[$profile['people']['staff'] ?? null, 'Staff'], [$profile['board']['size'] ?? null, 'Board members'], [$profile['beneficiaries'] ?? null, 'People reached a year'], [$profile['people']['volunteers'] ?? null, 'Volunteers'], [$profile['identity']['branches'] ?? null, 'Branches'], [$profile['people']['staff_under_30'] ?? null, 'Staff under 30']] as [$value, $label])
                        <div>
                            <dt class="text-[0.78125rem] text-on-surface-variant">{{ $label }}</dt>
                            <dd @class(['mt-0.5 text-[1.375rem] font-bold tabular-nums', 'text-on-surface' => $value !== null, 'text-[#8a8880]' => $value === null])>{{ $value !== null ? Viz::number($value) : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <x-empty-state icon="groups">These appear once your OHA form is approved.</x-empty-state>
            @endif
        </x-panel>

        <x-panel title="Your contacts at AAYMCA" subtitle="The staff assessing your movement">
            @forelse ($assessors as $person)
                <div class="flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0">
                    <x-avatar :user="$person" class="!size-[38px]" />
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold">{{ $person->name }}</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant truncate">{{ $person->title ?? $person->role->label() }}</span>
                    </span>
                    <a href="mailto:{{ $person->email }}" title="{{ $person->email }}" aria-label="Email {{ $person->name }} ({{ $person->email }})"
                       class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] hover:bg-[#f2f1ee] hover:text-on-surface">
                        <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/></svg>
                    </a>
                </div>
            @empty
                <x-empty-state icon="support_agent">AAYMCA has not yet named who assesses your movement.</x-empty-state>
            @endforelse
        </x-panel>

        <x-panel title="Reports and ODPs" subtitle="Everything approved for your movement">
            @forelse ($assessments as $assessment)
                <a href="{{ route('assessments.show', $assessment) }}" class="flex items-center gap-[14px] py-[12px] border-t border-[#f0eeea] first:border-t-0 first:pt-0 rounded-lg text-on-surface no-underline group">
                    <span class="flex-none size-[38px] rounded-[10px] bg-[#f2f1ee] grid place-items-center text-[#4a4950]">
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/></svg>
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold group-hover:underline">{{ $assessment->period_label }} assessment</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant truncate">
                            @if ($assessment->score)
                                Report, ODP and scores · {{ $assessment->score->band?->label }}, {{ number_format((float) $assessment->score->pct, 1) }}%
                            @else
                                {{ Stage::of($assessment)->label() }}
                            @endif
                        </span>
                    </span>
                    <svg class="size-4 flex-none text-[#8a8880]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
            @empty
                <x-empty-state icon="folder_open">No report or ODP has been approved for your movement yet.</x-empty-state>
            @endforelse
        </x-panel>
    </div>
</x-layouts.app>
