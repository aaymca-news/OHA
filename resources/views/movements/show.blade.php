<x-layouts.app :title="$movement->name" :subtitle="$movement->city.' · '.$movement->zone->name.' · '.$movement->membershipStatus->label">
    <div class="grid sm:grid-cols-3 gap-md">
        <x-stat-tile label="Latest health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:sub><x-band-chip :band="$status->band" /></x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Points" :value="$status->points_achieved !== null ? rtrim(rtrim(number_format((float) $status->points_achieved, 2), '0'), '.') : '—'" :unit="$status->points_available ? ' / '.$status->points_available : null">
            <x-slot:sub>{{ $status->last_assessed_on ? 'Assessed '.$status->last_assessed_on->format('M Y') : 'No approved form yet' }}</x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Next assessment" :value="$status->next_assessment_on?->format('M Y') ?? 'Not scheduled'">
            <x-slot:sub>
                @if ($status->reassessment_overdue)
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
            <x-card title="Board Chairperson" subtitle="The movement’s one user. Reads the approved report and signs the ODP.">
                @if ($chair)
                    <p class="text-[0.875rem] flex flex-wrap items-center gap-sm"><x-avatar :user="$chair" /> {{ $chair->name }}
                        @if ($chair->title)
                            <span class="text-on-surface-variant">· {{ $chair->title }}</span>
                        @endif
                        <x-chip tone="info" icon="draw">Signs the ODP</x-chip>
                    </p>
                @else
                    <x-empty-state icon="person_off">No Board Chairperson yet, so the ODP cannot be signed.</x-empty-state>
                @endif
            </x-card>
        </div>
    </div>

    @if ($profile)
        @include('movements._profile')
    @endif

    <x-card title="Assessments">
        @if ($canOpen)
            <x-slot:actions>
                <form method="POST" action="{{ route('assessments.store', $movement) }}" class="flex flex-wrap items-end gap-sm">
                    @csrf
                    <x-input name="period_label" label="Period" :value="now()->format('M Y')" class="w-32" required />
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
            </div>
        @empty
            <x-empty-state icon="fact_check">No assessments yet.</x-empty-state>
        @endforelse
    </x-card>
</x-layouts.app>
