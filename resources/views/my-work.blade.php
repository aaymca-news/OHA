{{--
    My Work: each person's own work, in the site's newer style (design standard of 10 Nov
    2026). On top, the one overdue step waiting on them, if any (a slim banner); then their
    work groups as tiles, each with how many items it holds and how many wait on them (the
    chosen one opens below); then that group's items as compact rows, one action each.
    The work stays here, never on the dashboard (which is analytics only).
--}}
@php
    $waitingIn = fn (array $group) => count(array_filter($group['items'], fn ($i) => $i['actionable']));
    $current = collect($groups)->firstWhere('key', $active);

    // The most pressing: the first step waiting on them that is past its target date.
    $pressing = null;
    foreach ($groups as $group) {
        if (in_array($group['key'], \App\Queries\MyWork::QUIET, true)) {
            continue;
        }
        foreach ($group['items'] as $entry) {
            if ($entry['actionable'] && ($entry['days_left'] ?? 0) < 0) {
                $pressing = ['row' => \App\Support\WorkRow::from($entry, $group['key']), 'days' => $entry['days_left']];
                break 2;
            }
        }
    }
@endphp
<x-layouts.app :title="__('oha.work.title')" :subtitle="__('oha.work.subtitle')">
    @include('demo.visitor-approvals')

    @if ($pressing)
        <x-action-banner
            :title="$pressing['row']['title'].' · '.($pressing['days'] === -1 ? __('oha.work.overdue_one') : __('oha.work.overdue', ['days' => abs($pressing['days'])]))"
            :href="$pressing['row']['url']" :button="$pressing['row']['button']" />
    @endif

    {{-- The groups, as tiles (one group alone needs no choosing). --}}
    @if (count($groups) > 1)
        <nav aria-label="Work groups" class="grid gap-sm sm:gap-md grid-cols-2 sm:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
            @foreach ($groups as $group)
                @php
                    $on = $active === $group['key'];
                    $count = count($group['items']);
                    $waiting = in_array($group['key'], \App\Queries\MyWork::QUIET, true) ? 0 : $waitingIn($group);
                @endphp
                <x-filter-tile :href="route('my-work', ['group' => $group['key']])" :active="$on" :label="__('oha.work.groups.'.$group['key'])" :value="$count">
                    @if ($waiting > 0)
                        <span class="inline-flex items-center gap-[6px] font-semibold text-brand-red-ink">
                            <span class="size-[7px] rounded-full bg-brand-red" aria-hidden="true"></span>{{ $waiting }} waiting on you
                        </span>
                    @else
                        {{ match ($group['key']) { 'gaps' => $count > 0 ? 'To follow up' : 'None open', 'sign' => $count > 0 ? 'Optional' : 'Nothing to sign', default => 'Nothing waiting on you' } }}
                    @endif
                </x-filter-tile>
            @endforeach
        </nav>
    @endif

    {{-- The chosen group's items --}}
    @if ($current)
        <x-panel flush :title="__('oha.work.groups.'.$current['key'])" :subtitle="__('oha.work.about.'.$current['key'])">
            @if ($current['items'] === [])
                <div class="flex flex-col items-center gap-sm px-lg py-xl text-center">
                    <span class="grid size-[48px] place-items-center rounded-full bg-good-wash text-good-ink" aria-hidden="true">
                        <span class="material-symbols-outlined">task_alt</span>
                    </span>
                    <p class="text-[0.9375rem] font-semibold">{{ __('oha.work.empty') }}</p>
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ __('oha.work.empty_hint') }}</p>
                </div>
            @else
                <ul class="flex flex-col">
                    @foreach ($current['items'] as $entry)
                        @include('partials.work-row', ['entry' => $entry, 'group' => $current['key']])
                    @endforeach
                </ul>
            @endif
        </x-panel>
    @else
        <x-panel>
            <p class="text-[0.9375rem] font-semibold">Nothing is waiting on you.</p>
        </x-panel>
    @endif
</x-layouts.app>
