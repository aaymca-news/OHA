<x-layouts.app :title="$movement->name" :subtitle="$movement->city.' · '.$movement->zone->name.' · '.$movement->membershipStatus->label">
    @can('update', $movement)
        <x-button variant="link" icon="edit" :href="route('admin.movements.edit', $movement)" class="self-start -mt-sm text-[0.875rem]">Edit the movement’s details</x-button>
    @endcan
    <div class="grid sm:grid-cols-3 gap-md">
        <x-stat-tile label="Latest health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:sub><x-band-chip :band="$status->band" :under-way="$underWay !== null" /></x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Points" :value="$status->points_achieved !== null ? \App\Support\Viz::figure((float) $status->points_achieved) : '—'" :unit="$status->points_available ? ' / '.$status->points_available : null">
            <x-slot:sub>{{ $status->last_assessed_on ? 'Assessed '.$status->last_assessed_on->asMonth() : 'No approved form yet' }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Next assessment" :value="$status->next_assessment_on?->asMonth() ?? 'Not scheduled'">
            <x-slot:sub>
                @if ($movement->followUpPaused())
                    <x-chip tone="warning" icon="pause_circle">Paused until {{ $movement->follow_up_paused_until->asDay() }}</x-chip>
                @elseif ($status->reassessment_overdue)
                    <x-chip tone="critical" icon="alarm">Overdue</x-chip>
                @elseif ($status->latest_assessment_id)
                    Every {{ $status->reassess_months }} months at this band
                @else
                    {{ $movement->planned_assessment_label ?? 'Not planned yet' }}
                @endif
            </x-slot:sub>
        </x-stat-tile>
    </div>

    <div class="grid lg:grid-cols-3 gap-md items-start">
        <x-card title="Category scores" :subtitle="$latest ? 'From the '.$latest->period_label.' assessment.' : null" class="lg:col-span-2 min-w-0">
            @if ($latest)
                <x-score-table :rows="$rows" :total="$latest->score" />
            @else
                <x-empty-state icon="bar_chart">{{ $movement->name }} has no approved OHA form yet, so it has no rating.</x-empty-state>
            @endif
        </x-card>

        <div class="flex flex-col gap-md">
            @if (auth()->user()->isSecretariat())
                @include('movements._assessors')
            @endif
            <x-card title="The movement’s users" subtitle="Each sees only this movement.">
                @foreach ([
                    ['Board Chairperson', $chair, 'draw', 'Signs the ODP', 'No Board Chairperson yet, so the ODP cannot be signed.'],
                    ['National General Secretary', $generalSecretary, 'upload_file', 'Supplies the evidence', 'No National General Secretary yet, so AAYMCA staff add the evidence.'],
                ] as [$role, $person, $icon, $does, $none])
                    <div class="flex flex-col gap-xs">
                        <p class="text-[0.8125rem] font-semibold text-on-surface-variant">{{ $role }}</p>
                        @if ($person)
                            <p class="text-[0.875rem] flex flex-wrap items-center gap-sm"><x-avatar :user="$person" /> {{ $person->name }}
                                <x-chip tone="info" :icon="$icon">{{ $does }}</x-chip>
                            </p>
                        @else
                            <p class="text-[0.875rem] text-on-surface-variant">{{ $none }}</p>
                        @endif
                    </div>
                @endforeach
            </x-card>
            <a href="{{ route('evidence.index', $movement) }}" class="flex items-center gap-sm p-md rounded-lg border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem] hover:border-primary">
                <span class="material-symbols-outlined text-[1.5rem] text-primary" aria-hidden="true">folder_open</span>
                <span class="flex-1">
                    <span class="block font-semibold text-primary">Evidence library</span>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $evidenceCount === 0 ? 'No documents yet' : $evidenceCount.' '.\Illuminate\Support\Str::plural('document', $evidenceCount) }}, linked to the OHA form and the ODP</span>
                </span>
                <span class="material-symbols-outlined text-[1.25rem] text-on-surface-variant" aria-hidden="true">chevron_right</span>
            </a>
        </div>
    </div>

    @if ($history->count() > 1)
        @include('movements._score-history')
    @endif

    @if ($profile)
        @include('movements._profile')
    @endif

    @if ($followUpProgress && $followUpProgress['summary']['total'] > 0)
        <x-card title="ODP follow-up" :subtitle="'How the '.$lastDone->period_label.' ODP is being carried out. Tasks count as done once an assessor confirms them.'">
            <x-slot:actions><x-button :href="route('follow-up.show', $lastDone)" size="sm" variant="outline" icon="checklist">Open the follow-up</x-button></x-slot:actions>
            @include('follow-up._progress', ['progress' => $followUpProgress])
        </x-card>
    @endif

    <x-card title="Assessments">
        @if ($underWay)
            {{-- One assessment at a time: no "Start new assessment" while this one runs. --}}
            <x-slot:actions>
                <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                    <x-chip tone="info" icon="play_circle">Assessment started</x-chip>
                    @can('view', $underWay)
                        <a href="{{ route('assessments.show', $underWay) }}" class="font-semibold text-primary underline">{{ $underWay->period_label }}</a>
                    @else
                        <span class="font-semibold">{{ $underWay->period_label }}</span>
                    @endcan
                </span>
            </x-slot:actions>
        @elseif ($lastDone && $followingUp)
            {{-- Stage 1 done: the ODP is signed and its follow-ups are under way until the next assessment is due. --}}
            <x-slot:actions>
                <div class="flex flex-col items-end gap-xs">
                    <span class="inline-flex flex-wrap items-center gap-sm text-[0.875rem]">
                        <x-chip tone="good" icon="verified">Stage 1 done</x-chip>
                        <x-chip tone="info" icon="autorenew">Follow-ups ongoing</x-chip>
                        @can('view', $lastDone)
                            <a href="{{ route('assessments.show', ['assessment' => $lastDone, 'tab' => 'odp']) }}" class="font-semibold text-primary underline">{{ $lastDone->period_label }}</a>
                        @else
                            <span class="font-semibold">{{ $lastDone->period_label }}</span>
                        @endcan
                        @can('viewFollowUp', $lastDone)
                            <x-button :href="route('follow-up.show', $lastDone)" size="sm" icon="checklist">ODP follow-up</x-button>
                        @endcan
                    </span>
                    @if ($canOpen)
                        <details class="text-[0.8125rem]">
                            <summary class="cursor-pointer text-primary underline text-right">Start the next assessment early</summary>
                            <form method="POST" action="{{ route('assessments.store', $movement) }}" class="flex flex-wrap items-end gap-sm mt-sm">
                                @csrf
                                <x-input name="period_label" label="Period" :value="now()->asMonth()" class="w-32" required />
                                <x-input name="period_start" label="Starting month" type="month" :value="now()->format('Y-m')" required />
                                <x-button>Start new assessment</x-button>
                            </form>
                        </details>
                    @endif
                </div>
            </x-slot:actions>
        @elseif ($canOpen)
            <x-slot:actions>
                <form method="POST" action="{{ route('assessments.store', $movement) }}" class="flex flex-wrap items-end gap-sm">
                    @csrf
                    <x-input name="period_label" label="Period" :value="now()->asMonth()" class="w-32" required />
                    <x-input name="period_start" label="Starting month" type="month" :value="now()->format('Y-m')" required />
                    <x-button>Start new assessment</x-button>
                </form>
            </x-slot:actions>
        @endif

        @forelse ($assessments as $assessment)
            @php($statuses = $assessment->artefacts->keyBy(fn ($a) => $a->kind->value))
            <div class="flex flex-wrap items-center gap-sm p-sm rounded border-[1.5px] border-outline-variant">
                <a href="{{ route('assessments.show', $assessment) }}" class="text-[0.875rem] font-semibold text-primary underline flex-1 min-w-40">{{ $assessment->period_label }}</a>
                @foreach (['form', 'report', 'odp'] as $kind)
                    <span class="text-[0.8125rem] text-on-surface-variant">{{ __('oha.artefact.'.$kind) }}</span>
                    <x-state-chip :state="$statuses[$kind]->status->effective_state" />
                    @if ($kind === 'odp')
                        <x-validation-chip :status="$statuses[$kind]->status" />
                    @endif
                @endforeach
                @if ($assessment->score)
                    <x-band-chip :band="$assessment->score->band" />
                @endif
                {{-- A signed ODP: its follow-up (tasks, meetings, the annual review and carry-over). --}}
                @if ($assessment->workItem === null && auth()->user()->can('viewFollowUp', $assessment))
                    <x-button :href="route('follow-up.show', $assessment)" variant="link" icon="checklist" class="text-[0.8125rem]">Follow-up</x-button>
                @endif
            </div>
        @empty
            <x-empty-state icon="fact_check">No assessments yet.</x-empty-state>
        @endforelse
    </x-card>
</x-layouts.app>
