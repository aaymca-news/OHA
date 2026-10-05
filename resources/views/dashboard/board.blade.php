<x-layouts.app :title="$status->name" subtitle="Your movement’s organizational health, and the approved report and ODP.">
    <div class="grid sm:grid-cols-3 gap-md">
        <x-stat-tile label="Health score" :value="$status->pct !== null ? number_format((float) $status->pct, 1) : '—'" :unit="$status->pct !== null ? '%' : null">
            <x-slot:sub><x-band-chip :band="$status->band" /></x-slot:sub>
        </x-stat-tile>
        <x-stat-tile label="Points" :value="$status->points_achieved !== null ? rtrim(rtrim(number_format((float) $status->points_achieved, 2), '0'), '.') : '—'" :unit="$status->points_available ? ' / '.$status->points_available : null" />
        <x-stat-tile label="Next assessment" :value="$status->next_assessment_on?->format('M Y') ?? 'Not scheduled'" />
    </div>

    <x-card title="Waiting on you">
        @if ($work->isEmpty())
            <x-empty-state icon="task_alt">Nothing is waiting on you.</x-empty-state>
        @else
            <ul class="flex flex-col gap-sm">
                @foreach ($work as $entry)
                    @include('partials.work-row', ['entry' => $entry])
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card title="Approved reports and ODPs">
        @forelse ($assessments as $assessment)
            <a href="{{ route('assessments.show', $assessment) }}" class="text-[0.875rem] text-primary underline">{{ $status->name }} · {{ $assessment->period_label }}</a>
        @empty
            <x-empty-state icon="folder_open">No report or ODP has been approved for your movement yet.</x-empty-state>
        @endforelse
    </x-card>
</x-layouts.app>
