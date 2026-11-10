{{--
    A movement's health categories as score bars, weakest first. Categories not yet
    weighted are named beneath. Expects $rows from App\Oha\CategoryRows::for().
--}}
@props(['rows'])
@php
    $scored = collect($rows)->filter(fn ($r) => $r['max'] !== null)->sortBy(fn ($r) => (float) $r['pct'])
        ->map(fn ($r) => ['label' => $r['name'], 'pct' => $r['pct'], 'band_code' => $r['band_code'], 'band' => $r['band'],
            'title' => \App\Support\Viz::figure($r['points']).' of '.$r['max'].' points'])
        ->values()->all();
    $unweighted = collect($rows)->filter(fn ($r) => $r['max'] === null)->pluck('name');
@endphp
<x-score-bars :rows="$scored" {{ $attributes }}>
    @if ($unweighted->isNotEmpty())
        <span>Not yet weighted: {{ $unweighted->join(', ') }}</span>
    @endif
</x-score-bars>
