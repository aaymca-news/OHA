{{--
    The Alliance's journeys (decided 12 Nov 2026): each movement's latest cycle, from Baseline 1
    (its closed assessment) to its newest baseline, for AAYMCA staff. Key figures, the
    Alliance's average score at each baseline, and every movement, the biggest gain first.
    Expects $journeys (from App\Queries\Journeys::alliance).
--}}
@php
    $summary = $journeys['summary'];
    $curve = collect($journeys['curve'])->map(fn (array $c) => [
        'label' => 'Baseline '.$c['number'],
        'pct' => $c['pct'],
        'note' => $c['n'] === 0 ? 'no movement has reached it yet' : 'the average of '.$c['n'].' '.\Illuminate\Support\Str::plural('movement', $c['n']),
    ])->all();
@endphp
<x-layouts.app title="Journeys" subtitle="Each movement’s latest cycle, from Baseline 1 to its newest baseline">
    @if ($summary['movements'] === 0)
        <x-empty-state icon="trending_up">No assessment is closed yet: a movement’s journey starts at Baseline 1, when its assessment is closed.</x-empty-state>
    @else
        <section aria-label="Key figures" class="grid gap-md sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi label="In a cycle" :value="(string) $summary['movements']">{{ $summary['followed'] }} with at least one follow-up closed</x-kpi>
            <x-kpi label="Improved" :value="(string) $summary['improved']">Higher now than at Baseline 1{{ $summary['declined'] > 0 ? ' · '.$summary['declined'].' lower' : '' }}</x-kpi>
            <x-kpi label="Moved up a band" :value="(string) $summary['band_up']">Since Baseline 1</x-kpi>
            <x-kpi label="Average change" :value="$summary['mean_change'] === null ? '—' : ($summary['mean_change'] > 0 ? '+' : '').number_format($summary['mean_change'], 1)" :unit="$summary['mean_change'] === null ? null : ' pts'">Percentage points, across those followed up</x-kpi>
        </section>

        <x-panel title="The Alliance’s average score at each baseline" subtitle="Each point averages the movements that have reached that baseline, so the movements behind each point may differ: hover a baseline to see how many.">
            <x-viz.growth-curve :points="$curve" />
        </x-panel>

        <x-panel flush title="Every movement" subtitle="The biggest gain first. Change is in percentage points, from Baseline 1 to the newest baseline.">
            <div class="px-lg pt-md">
                @include('journeys._rows', ['rows' => $journeys['rows'], 'limit' => null])
            </div>
        </x-panel>
    @endif
</x-layouts.app>
