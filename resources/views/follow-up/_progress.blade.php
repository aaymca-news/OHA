{{--
    An ODP's progress at a glance, as set at the last follow-up: done of all, in progress,
    blocked, and each strategic priority's bar. Expects $progress (from
    App\Queries\OdpProgress::of).
--}}
@php
    $s = $progress['summary'];
@endphp
<div class="flex flex-col gap-sm">
    <x-bar wide :value="$s['done']" :max="max(1, $s['total'])" tone="good" :label="$s['done'].' of '.$s['total'].' tasks done · '.$s['pct'].'%'" />
    <div class="flex flex-wrap gap-xs">
        @if ($s['in_progress'] > 0)<x-chip tone="warning" icon="pending">{{ $s['in_progress'] }} in progress</x-chip>@endif
        @if ($s['blocked'] > 0)<x-chip tone="serious" icon="block">{{ $s['blocked'] }} blocked</x-chip>@endif
        @if ($s['blocked'] === 0 && $s['total'] > 0)<x-chip tone="good" icon="check">Nothing blocked</x-chip>@endif
    </div>
    @if (count($progress['priorities']) > 1)
        <ul class="flex flex-col gap-xs">
            @foreach ($progress['priorities'] as $p)
                <li class="grid min-[30rem]:grid-cols-[minmax(8rem,14rem)_1fr] items-center gap-x-sm gap-y-0.5 text-[0.8125rem]">
                    <span class="truncate" title="{{ $p['priority'] }}">{{ \Illuminate\Support\Str::title(mb_strtolower($p['priority'])) }}</span>
                    <x-bar :value="$p['done']" :max="max(1, $p['total'])" tone="good" :label="$p['done'].' / '.$p['total'].($p['blocked'] > 0 ? ' · '.$p['blocked'].' blocked' : '')" wide />
                </li>
            @endforeach
        </ul>
    @endif
</div>
