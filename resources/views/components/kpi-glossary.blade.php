{{--
    "How these figures are worked out" (KPI dictionary, 23 Nov 2026): the definitions of the
    figures a dashboard shows, folded away at its foot, in the words of the ⓘ beside each.
      keys  the figures' keys in App\Support\Kpis, in reading order
--}}
@props(['keys'])
<details {{ $attributes->class('group bg-surface-container-lowest border border-[#e7e5e0] rounded-2xl shadow-[0_1px_2px_rgba(20,20,20,0.04)]') }}>
    <summary class="oha-summary flex items-center gap-md p-lg cursor-pointer list-none">
        <span class="flex-1 min-w-0">
            <span class="block text-[1.125rem] font-bold">How these figures are worked out</span>
            <span class="block text-[0.875rem] text-on-surface-variant">What each figure on this page means, and where it comes from.</span>
        </span>
        <span class="flex-none size-9 rounded-full border border-[#e4e1db] grid place-items-center text-[#4a4950] transition-transform group-open:rotate-180" aria-hidden="true">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </span>
    </summary>
    <dl class="grid gap-x-xl gap-y-md md:grid-cols-2 px-lg pb-lg pt-md border-t border-[#f0eeea]">
        @foreach (\App\Support\Kpis::many($keys) as $kpi)
            <div class="flex flex-col gap-[4px] text-[0.84375rem]">
                <dt class="font-semibold text-on-surface">{{ $kpi['label'] }}</dt>
                <dd class="text-on-surface">{{ $kpi['means'] }}</dd>
                <dd class="text-on-surface-variant">{{ $kpi['how'] }}</dd>
                @if ($kpi['mind'])
                    <dd class="text-on-surface-variant">{{ $kpi['mind'] }}</dd>
                @endif
            </div>
        @endforeach
    </dl>
</details>
