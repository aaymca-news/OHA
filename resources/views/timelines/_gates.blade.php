{{--
    The assessments under way (design standard of 10 Nov 2026): the missed and coming-up
    counts, then each gate's deadline on one shared date axis (a dot per gate: green done,
    red missed, an open ring still to come; the red line is today), or as a table.
--}}
<div class="flex flex-wrap items-stretch gap-sm sm:gap-md">
    <x-panel class="!px-[20px] !py-[16px] flex-1 min-w-[10rem]">
        <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Missed</p>
        <p @class(['text-[1.75rem] font-bold leading-tight tabular-nums', 'text-critical-ink' => $missed > 0])>{{ $missed }}</p>
        <p class="text-[0.8125rem] text-on-surface-variant">Gates past their deadline</p>
    </x-panel>
    <x-panel class="!px-[20px] !py-[16px] flex-1 min-w-[10rem]">
        <p class="text-[0.75rem] font-semibold uppercase tracking-[0.06em] text-on-surface-variant">Due within 7 days</p>
        <p class="text-[1.75rem] font-bold leading-tight tabular-nums">{{ $dueSoon }}</p>
        <p class="text-[0.8125rem] text-on-surface-variant">Coming up this week</p>
    </x-panel>
    <x-segmented label="View" variant="joined" class="self-end ml-auto">
        <x-segmented.link :href="route('timelines', ['tab' => 'gates'])" :active="$view === 'chart'" icon="timeline">Chart</x-segmented.link>
        <x-segmented.link :href="route('timelines', ['tab' => 'gates', 'view' => 'table'])" :active="$view === 'table'" icon="table_rows">Table</x-segmented.link>
    </x-segmented>
</div>

@if ($assessments->isEmpty())
    <x-panel><x-empty-state icon="event_note">No assessment is in progress{{ auth()->user()->oversees() ? '' : ' on the movements you assess' }}.</x-empty-state></x-panel>
@elseif ($view === 'chart')
    @php
        $span = max(1, $start->diffInDays($end));
        $at = fn ($date) => round($start->diffInDays($date) / $span * 100, 2);
        $months = collect(\Carbon\CarbonPeriod::create($start, '1 month', $end));
        $today = $at(now());
    @endphp
    <x-panel title="Gate deadlines" subtitle="Each assessment under way, its four gates on one shared timeline">
        <div class="overflow-x-auto">
            <div class="min-w-[720px] flex flex-col">
                <div class="grid grid-cols-[15rem_1fr] text-[0.75rem] text-on-surface-variant pb-sm border-b border-[#efede8]">
                    <span></span>
                    <div class="relative h-5">
                        @foreach ($months as $month)
                            <span class="absolute whitespace-nowrap {{ $loop->last && $at($month) > 90 ? '-translate-x-full' : ($loop->first ? '' : '-translate-x-1/2') }}" style="left: {{ $at($month) }}%">{{ $month->asMonth() }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($assessments as $assessment)
                    <div class="grid grid-cols-[15rem_1fr] items-center gap-sm py-[10px] border-b border-[#f2f1ec] last:border-0">
                        <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline']) }}" class="min-w-0 truncate text-[0.875rem] hover:underline">
                            <span class="font-semibold">{{ $assessment->movement->name }}</span> <span class="text-on-surface-variant">· {{ $assessment->period_label }}</span>
                        </a>
                        <div class="relative h-6">
                            <span class="absolute inset-x-0 top-1/2 h-0.5 -translate-y-1/2 rounded-full bg-[#ebe9e4]" aria-hidden="true"></span>
                            <span class="absolute inset-y-[-6px] w-0.5 bg-brand-red/70" style="left: {{ $today }}%" title="Today" aria-hidden="true"></span>
                            @foreach ($milestones[$assessment->id] ?? [] as $m)
                                @php($state = $m->done_at ? 'done' : ($m->overdue ? 'missed' : 'open'))
                                <span class="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full box-border
                                             {{ ['done' => 'bg-band-strong border-2 border-white', 'missed' => 'bg-band-critical border-2 border-white', 'open' => 'bg-surface-container-lowest border-2 border-on-surface'][$state] }}"
                                      style="left: {{ $at($m->done_at ?? $m->due_on) }}%"
                                      title="{{ $m->milestone_label }}: {{ ['done' => 'done '.optional($m->done_at)->format('j M'), 'missed' => 'missed, due '.$m->due_on->format('j M'), 'open' => 'due '.$m->due_on->format('j M')][$state] }}"
                                      role="img" aria-label="{{ $m->milestone_label }}, {{ $state }}"></span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <p class="flex flex-wrap gap-md pt-md text-[0.78125rem] text-on-surface-variant">
                    <span class="inline-flex items-center gap-[6px]"><span class="size-3 rounded-full bg-band-strong"></span>Done</span>
                    <span class="inline-flex items-center gap-[6px]"><span class="size-3 rounded-full box-border border-2 border-on-surface"></span>Still to come</span>
                    <span class="inline-flex items-center gap-[6px]"><span class="size-3 rounded-full bg-band-critical"></span>Missed</span>
                    <span class="inline-flex items-center gap-[6px]"><span class="w-0.5 h-3.5 bg-brand-red/70"></span>Today</span>
                </p>
            </div>
        </div>
    </x-panel>
@else
    <x-panel flush>
        <x-table bare>
            <x-slot:head>
                <tr>
                    <th>Assessment</th>
                    @foreach (($milestones->first() ?? collect()) as $m)
                        <th>{{ $m->milestone_label }}</th>
                    @endforeach
                    <th>Other steps</th>
                </tr>
            </x-slot:head>
            @foreach ($assessments as $assessment)
                <tr class="border-t border-[#f2f1ec] hover:bg-[#fafaf8]">
                    <td><a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline']) }}" class="font-semibold hover:underline">{{ $assessment->movement->name }}</a>
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }}</span></td>
                    @foreach ($milestones[$assessment->id] ?? [] as $m)
                        <td class="whitespace-nowrap">
                            @if ($m->done_at)
                                <x-status-pill tone="good">Done {{ $m->done_at->format('j M') }}</x-status-pill>
                            @elseif ($m->overdue)
                                <x-status-pill tone="critical">Missed {{ $m->due_on->format('j M') }}</x-status-pill>
                            @else
                                <span class="text-[0.875rem]">{{ $m->due_on->asDay() }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="text-[0.8125rem] text-on-surface-variant">
                        {{ ($steps[$assessment->id] ?? collect())->map(fn ($s) => $s->label.($s->due_on ? ' ('.$s->due_on->format('j M').')' : '').($s->done_on ? ' ✓' : ''))->join('; ') ?: '—' }}
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-panel>
@endif
