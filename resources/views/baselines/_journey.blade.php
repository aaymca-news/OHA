{{--
    One cycle's journey (decided 12 Nov 2026): the overall score at each baseline as a
    growth curve, then each category at each baseline, with the ODP tasks done, the evidence
    counted and the answers changed at each. Expects $assessment and $journey (from
    App\Queries\Journeys::of), and optionally $link (true: a link to Scores and baselines).
--}}
@php
    $steps = collect($journey);
    $newest = $steps->last();
    $gain = $steps->count() > 1 ? round($newest['pct'] - $steps->first()['pct'], 1) : null;
@endphp
<x-panel id="journey" stack :title="'The '.$assessment->period_label.' cycle’s journey'"
         :subtitle="$steps->count() === 1
            ? 'Baseline 1, from the closed assessment. Each follow-up closed adds the next baseline.'
            : 'From Baseline 1 to Baseline '.$newest['number'].': '.($gain > 0 ? '+' : '').number_format((float) $gain, 1).' percentage points.'">
    <x-slot:actions>
        <x-row-action :href="route('baselines.pdf', $assessment)">Download PDF</x-row-action>
        <x-row-action :href="route('baselines.workbook', $assessment)">Excel</x-row-action>
        @if ($link ?? false)
            <x-more-link :href="route('baselines.show', $assessment)">Scores and baselines</x-more-link>
        @endif
    </x-slot:actions>
    <x-viz.growth-curve :points="\App\Queries\Journeys::curve($steps)" />
    <x-score-columns :columns="$steps->map(fn (array $step) => [
            'label' => $step['label'],
            'href' => null,
            'rows' => $step['rows'],
            'pct' => $step['pct'],
            'band' => $step['band'],
        ])"
        :extra="[
            ['label' => 'ODP tasks done', 'values' => $steps->map(fn ($s) => $s['tasks_total'] !== null ? $s['tasks_done'].' of '.$s['tasks_total'] : '—')->all()],
            ['label' => 'Evidence counted', 'values' => $steps->map(fn ($s) => (string) $s['evidence'])->all()],
            ['label' => 'Answers changed', 'values' => $steps->map(fn ($s) => $s['number'] === 1 ? '—' : (string) $s['changed'])->all()],
        ]" />
</x-panel>
