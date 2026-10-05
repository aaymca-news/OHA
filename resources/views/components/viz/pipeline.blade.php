{{--
    Where all the movements are in Stage 1, as one bar of ordered steps: grey for not yet
    assessed, then one hue darkening from the OHA form to complete. Counts are printed in
    the legend and on hover.
    Expects $pipeline from AllianceInsights::pipeline().
--}}
@props(['pipeline'])
@php
    $steps = [
        'not_assessed' => ['Not yet assessed', 'bg-surface-container-high'],
        'form' => ['OHA form', 'bg-heat-1'],
        'report' => ['Report', 'bg-heat-2'],
        'odp' => ['ODP', 'bg-heat-3'],
        'sign' => ['Awaiting signature', 'bg-heat-4'],
        'complete' => ['Stage 1 complete', 'bg-heat-5'],
    ];
    $total = max(1, $pipeline['total']);
@endphp
<div class="flex flex-col gap-sm">
    <div class="flex gap-[2px] h-6 rounded overflow-hidden" role="img"
         aria-label="{{ collect($steps)->map(fn ($s, $k) => $s[0].': '.$pipeline[$k])->join(', ') }}">
        @foreach ($steps as $key => [$label, $fill])
            @continue($pipeline[$key] === 0)
            <span class="{{ $fill }} h-full" style="width: {{ $pipeline[$key] * 100 / $total }}%" title="{{ $label }}: {{ $pipeline[$key] }}"></span>
        @endforeach
    </div>
    <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-sm">
        @foreach ($steps as $key => [$label, $fill])
            <li class="flex items-start gap-xs">
                <span class="mt-1 w-3 h-3 rounded-sm shrink-0 {{ $fill }}" aria-hidden="true"></span>
                <span>
                    <span class="block text-[1.25rem] font-bold text-primary leading-tight">{{ $pipeline[$key] }}</span>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $label }}</span>
                </span>
            </li>
        @endforeach
    </ul>
</div>
