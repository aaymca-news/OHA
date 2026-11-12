{{--
    Category scores across the movement's scored assessments, oldest on the left, each with
    its change in percentage points since the one before (each assessment's score as it
    stands: its newest baseline). Expects $history.
--}}
<x-panel title="Scores over time" :subtitle="'Each category’s score in the last '.$history->count().' approved assessments, with the change since the one before, in percentage points.'">
    <x-score-columns :columns="$history->map(fn (array $column) => [
        'label' => $column['assessment']->period_label,
        'href' => route('assessments.show', $column['assessment']),
        'rows' => $column['rows']->all(),
        'pct' => (float) $column['assessment']->score->pct,
        'band' => $column['assessment']->score->band,
    ])" />
</x-panel>
