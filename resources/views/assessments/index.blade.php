<x-layouts.app :title="__('oha.nav.assessments')" subtitle="Every assessment, who holds it now, and when they must act.">
    <div class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg overflow-x-auto">
        <table class="w-full text-[0.875rem]">
            <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                <tr class="border-b-[1.5px] border-outline-variant">
                    <th class="px-md py-sm">Assessment</th>
                    <th class="px-md py-sm">Form</th>
                    <th class="px-md py-sm">Report</th>
                    <th class="px-md py-sm">ODP</th>
                    <th class="px-md py-sm">With</th>
                    <th class="px-md py-sm">Due</th>
                    <th class="px-md py-sm">Score</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assessments as $assessment)
                    @php($a = $assessment->artefacts->keyBy(fn ($x) => $x->kind->value))
                    <tr class="border-b border-surface-container last:border-0">
                        <td class="px-md py-sm"><a href="{{ route('assessments.show', $assessment) }}" class="font-semibold text-primary underline">{{ $assessment->movement->name }}</a>
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }} · opened by {{ $assessment->opener->name }}</span></td>
                        @foreach (['form', 'report', 'odp'] as $kind)
                            <td class="px-md py-sm"><span class="flex flex-col items-start gap-xs"><x-state-chip :state="$a[$kind]->status->effective_state" />@if ($kind !== 'form')<x-validation-chip :status="$a[$kind]->status" />@endif</span></td>
                        @endforeach
                        <td class="px-md py-sm">{{ $assessment->workItem?->holder_role->label() ?? 'Stage 1 complete' }}</td>
                        <td class="px-md py-sm">@if ($assessment->workItem) <x-due-chip :days="$assessment->workItem->days_left" /> @else — @endif</td>
                        <td class="px-md py-sm">@if ($assessment->score) <x-band-chip :band="$assessment->score->band" /> {{ number_format((float) $assessment->score->pct, 1) }}% @else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-md py-md"><x-empty-state icon="fact_check">No assessments yet. Open one from a movement’s page.</x-empty-state></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
