<x-layouts.app title="Search" :subtitle="$q !== '' ? 'Results for “'.$q.'”' : 'Type at least two letters in the search box.'">
    @if (mb_strlen($q) >= 2)
        <x-card title="National Movements">
            @forelse ($movements as $movement)
                <a href="{{ route('movements.show', $movement) }}" class="text-[0.875rem] text-primary underline">{{ $movement->name }} <span class="text-on-surface-variant no-underline">· {{ $movement->city }}, {{ $movement->country }}</span></a>
            @empty
                <x-empty-state icon="search_off">No movement matches.</x-empty-state>
            @endforelse
        </x-card>
        <x-card title="Assessments">
            @forelse ($assessments as $assessment)
                <p class="text-[0.875rem] flex flex-wrap items-center gap-sm">
                    <a href="{{ route('assessments.show', $assessment) }}" class="text-primary underline">{{ $assessment->movement->name }} · {{ $assessment->period_label }}</a>
                    <span class="text-on-surface-variant">{{ $assessment->workItem?->holder_role->label() ?? 'Stage 1 complete' }}</span>
                </p>
            @empty
                <x-empty-state icon="search_off">No assessment matches.</x-empty-state>
            @endforelse
        </x-card>
    @endif
</x-layouts.app>
