{{--
    Each movement in follow-up (decided 12 Nov 2026): its three follow-ups as agreed with it
    (closed, open, planned, or with no date yet; overdue once the date has passed), and how
    far its ODP has come as set at the last follow-up. The counts on top are also filters
    (choosing one again shows them all). Expects $rows, $counts and $state.
--}}
@php
    $filters = [
        'overdue' => ['Overdue', 'critical', 'Past their agreed date'],
        'open' => ['Open now', null, 'Evidence and notes coming in'],
        'unset' => ['No date yet', null, 'Next follow-up to agree'],
    ];
@endphp

<section aria-label="Filter the movements" class="grid gap-sm sm:gap-md grid-cols-2 lg:grid-cols-4">
    <x-filter-tile :href="route('timelines')" :active="$state === null" label="In follow-up" :value="$counts['all']">
        Every movement in follow-up
    </x-filter-tile>
    @foreach ($filters as $key => [$label, $tone, $note])
        <x-filter-tile :href="route('timelines', $state === $key ? [] : ['state' => $key])" :active="$state === $key" :label="$label" :value="$counts[$key] ?? 0" :tone="$tone">
            {{ $state === $key ? 'Showing these · choose again for all' : $note }}
        </x-filter-tile>
    @endforeach
</section>

@if ($rows->isEmpty())
    <x-panel>
        <x-empty-state icon="event">
            {{ $state ? 'No movement matches this filter.' : 'No movement is in follow-up yet. Follow-up begins once an assessment is closed.' }}
        </x-empty-state>
    </x-panel>
@else
    <x-panel flush>
        <ul class="flex flex-col">
            @foreach ($rows as $row)
                @php
                    $assessment = $row['assessment'];
                    $s = $row['summary'];
                @endphp
                <li class="flex flex-wrap items-center gap-x-lg gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
                    <div class="flex-[1_1_12rem] min-w-0">
                        <p class="text-[0.9375rem] font-semibold truncate">{{ $assessment->movement->name }}</p>
                        <p class="text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }} · {{ $s['done'] }} of {{ $s['total'] }} tasks done{{ $s['blocked'] > 0 ? ' · '.$s['blocked'].' blocked' : '' }}</p>
                    </div>
                    <ol class="flex-[999_1_24rem] flex flex-wrap gap-xs" aria-label="Its follow-ups">
                        @foreach ($row['schedule'] as $f)
                            @php
                                [$tone, $text] = match (true) {
                                    $f['state'] === \App\Oha\FollowUpSchedule::LOCKED => ['good', 'Closed '.$f['window']->locked_at->asDay()],
                                    $f['state'] === \App\Oha\FollowUpSchedule::SKIPPED => ['neutral', 'Not held'],
                                    $f['overdue'] => ['critical', 'Overdue · '.$f['due_on']->asDay()],
                                    $f['state'] === \App\Oha\FollowUpSchedule::OPEN => ['info', 'Open'.($f['due_on'] ? ' · '.$f['due_on']->asDay() : '')],
                                    $f['due_on'] !== null => ['neutral', 'Planned '.$f['due_on']->asDay()],
                                    default => ['neutral', 'No date yet'],
                                };
                            @endphp
                            <li><x-status-pill :tone="$tone"><span class="font-semibold">{{ $f['number'] }}</span>&nbsp;· {{ $text }}</x-status-pill></li>
                        @endforeach
                    </ol>
                    <x-row-action :href="route('follow-up.show', $assessment)">Open the follow-up<span class="sr-only">: {{ $assessment->movement->name }}</span></x-row-action>
                </li>
            @endforeach
        </ul>
    </x-panel>
@endif
