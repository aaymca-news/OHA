<x-layouts.app :title="__('oha.nav.timelines')" subtitle="Every ongoing assessment’s deadlines on one shared date axis.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-sm">
            <x-chip tone="critical" icon="alarm">Missed {{ $missed }}</x-chip>
            <x-chip tone="warning" icon="schedule">Due within 7 days {{ $dueSoon }}</x-chip>
            <nav class="flex gap-xs" aria-label="View">
                <a href="{{ route('timelines') }}" @class(['px-sm py-1 rounded border-[1.5px] text-[0.8125rem]', 'border-primary bg-primary text-on-primary' => $view === 'chart', 'border-outline-variant' => $view !== 'chart'])>Chart</a>
                <a href="{{ route('timelines', ['view' => 'table']) }}" @class(['px-sm py-1 rounded border-[1.5px] text-[0.8125rem]', 'border-primary bg-primary text-on-primary' => $view === 'table', 'border-outline-variant' => $view !== 'table'])>Table</a>
            </nav>
        </div>
    </x-slot:actions>

    @if ($assessments->isEmpty())
        <x-empty-state icon="event_note">No assessment is in progress.</x-empty-state>
    @elseif ($view === 'chart')
        @php
            $span = max(1, $start->diffInDays($end));
            $at = fn ($date) => round($start->diffInDays($date) / $span * 100, 2);
            $months = collect(\Carbon\CarbonPeriod::create($start, '1 month', $end));
        @endphp
        <div class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md overflow-x-auto">
            <div class="min-w-[720px] flex flex-col gap-sm">
                <div class="grid grid-cols-[14rem_1fr] text-[0.8125rem] text-on-surface-variant">
                    <span></span>
                    <div class="relative h-5">
                        @foreach ($months as $month)
                            <span class="absolute whitespace-nowrap {{ $loop->last && $at($month) > 90 ? '-translate-x-full' : ($loop->first ? '' : '-translate-x-1/2') }}" style="left: {{ $at($month) }}%">{{ $month->format('M Y') }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($assessments as $assessment)
                    <div class="grid grid-cols-[14rem_1fr] items-center gap-sm">
                        <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline']) }}" class="text-[0.875rem] font-semibold text-primary underline truncate">
                            {{ $assessment->movement->name }} · {{ $assessment->period_label }}
                        </a>
                        <div class="relative h-8 rounded bg-surface-container-low">
                            <span class="absolute inset-y-0 w-0.5 bg-primary" style="left: {{ $at(now()) }}%" title="Today"></span>
                            @foreach ($milestones[$assessment->id] ?? [] as $m)
                                @php($state = $m->done_at ? 'done' : ($m->overdue ? 'missed' : 'open'))
                                <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 w-3.5 h-3.5 rotate-45 border-2 border-white
                                             {{ ['done' => 'bg-band-strong', 'missed' => 'bg-band-critical', 'open' => 'bg-primary-container'][$state] }}"
                                      style="left: {{ $at($m->done_at ?? $m->due_on) }}%"
                                      title="{{ $m->milestone_label }}: {{ ['done' => 'done '.optional($m->done_at)->format('j M'), 'missed' => 'missed, due '.$m->due_on->format('j M'), 'open' => 'due '.$m->due_on->format('j M')][$state] }}"
                                      role="img" aria-label="{{ $m->milestone_label }}, {{ $state }}"></span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <p class="text-[0.8125rem] text-on-surface-variant flex flex-wrap gap-md pt-sm">
                    <span class="flex items-center gap-xs"><span class="w-3 h-3 rotate-45 bg-band-strong"></span> Done</span>
                    <span class="flex items-center gap-xs"><span class="w-3 h-3 rotate-45 bg-primary-container"></span> Upcoming</span>
                    <span class="flex items-center gap-xs"><span class="w-3 h-3 rotate-45 bg-band-critical"></span> Missed</span>
                    <span class="flex items-center gap-xs"><span class="w-0.5 h-3 bg-primary"></span> Today</span>
                </p>
            </div>
        </div>
    @else
        <div class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg overflow-x-auto">
            <table class="w-full text-[0.875rem]">
                <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                    <tr class="border-b-[1.5px] border-outline-variant">
                        <th class="px-md py-sm">Assessment</th>
                        @foreach (($milestones->first() ?? collect()) as $m)
                            <th class="px-md py-sm">{{ $m->milestone_label }}</th>
                        @endforeach
                        <th class="px-md py-sm">Other steps</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assessments as $assessment)
                        <tr class="border-b border-surface-container last:border-0">
                            <td class="px-md py-sm"><a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline']) }}" class="font-semibold text-primary underline">{{ $assessment->movement->name }}</a>
                                <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }}</span></td>
                            @foreach ($milestones[$assessment->id] ?? [] as $m)
                                <td class="px-md py-sm whitespace-nowrap">
                                    @if ($m->done_at)
                                        <x-chip tone="good" icon="check_circle">{{ $m->done_at->format('j M') }}</x-chip>
                                    @elseif ($m->overdue)
                                        <x-chip tone="critical" icon="alarm">{{ $m->due_on->format('j M') }}</x-chip>
                                    @else
                                        {{ $m->due_on->format('j M Y') }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-md py-sm text-[0.8125rem]">
                                {{ ($steps[$assessment->id] ?? collect())->map(fn ($s) => $s->label.($s->due_on ? ' ('.$s->due_on->format('j M').')' : '').($s->done_on ? ' ✓' : ''))->join('; ') ?: '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
