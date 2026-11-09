<x-layouts.app :title="__('oha.nav.assessments')" subtitle="Hand out the work: who assesses each National Movement. Then every assessment, who holds it now, and when they must act.">
    <x-card id="assign" title="Who assesses each National Movement"
            subtitle="A movement can have more than one assessor. They assess its assessment under way, open the next one when it is due, and see it in their My Work. To replace someone, untick them and tick who takes over. Everyone added or removed is told, and every change is in the audit trail.">
        <x-segmented label="Which movements">
            <x-segmented.link :href="route('assessments.index').'#assign'" :active="$show === 'all'">All {{ $total }}</x-segmented.link>
            <x-segmented.link :href="route('assessments.index', ['show' => 'unassigned']).'#assign'" :active="$show === 'unassigned'">Needing an assessor {{ $needing }}</x-segmented.link>
        </x-segmented>

        @if ($movements->isEmpty())
            <x-empty-state icon="task_alt">Every movement with work to do has an assessor.</x-empty-state>
        @else
            <ul class="flex flex-col">
                @foreach ($movements as $movement)
                    @php
                        $current = $underWay[$movement->id] ?? null;
                        $people = $assessorsOf[$movement->id] ?? collect();
                    @endphp
                    <li id="assessors-{{ $movement->slug }}" class="py-sm border-t border-surface-container first:border-0 flex flex-col gap-xs scroll-mt-24">
                        <div class="flex flex-wrap items-start gap-x-md gap-y-xs">
                            <div class="flex-1 min-w-[min(13rem,100%)]">
                                <a href="{{ route('movements.show', $movement) }}" class="font-semibold text-primary underline">{{ $movement->name }}</a>
                                <span class="block text-[0.8125rem] text-on-surface-variant">
                                    {{ $movement->zone->name }} ·
                                    @if ($current)
                                        <a href="{{ route('assessments.show', $current) }}" class="underline">{{ $current->period_label }}</a> · {{ $current->workItem->holder_role->label() }}
                                    @elseif ($movement->assessments_count === 0)
                                        Never assessed
                                    @else
                                        No assessment under way
                                    @endif
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-xs min-w-[min(13rem,100%)] sm:justify-end">
                                @forelse ($people as $person)
                                    <span class="inline-flex items-center gap-xs text-[0.875rem]"><x-avatar :user="$person" />{{ $person->name }}</span>
                                @empty
                                    <x-chip :tone="$current || $movement->assessments_count === 0 ? 'warning' : 'neutral'" icon="person_off">No assessor</x-chip>
                                @endforelse
                            </div>
                        </div>
                        <details class="text-[0.875rem]" @if ($errors->has('action') && old('movement') === $movement->slug) open @endif>
                            <summary class="cursor-pointer text-primary underline w-fit">{{ $people->isEmpty() ? 'Assign an assessor' : 'Add, replace or remove assessors' }}</summary>
                            <form method="POST" action="{{ route('movements.assessors', $movement) }}" class="flex flex-col gap-sm mt-sm p-sm rounded bg-surface-container-low">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="movement" value="{{ $movement->slug }}">
                                <fieldset class="flex flex-col gap-xs">
                                    <legend class="text-[0.8125rem] text-on-surface-variant mb-xs">Tick everyone who should assess {{ $movement->name }}.</legend>
                                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-md gap-y-xs">
                                        @foreach ($staff as $person)
                                            <label class="flex items-center gap-sm">
                                                <input type="checkbox" name="assessor_ids[]" value="{{ $person->id }}" @checked($people->contains('id', $person->id))>
                                                <span>{{ $person->name }} <span class="text-[0.8125rem] text-on-surface-variant">{{ $person->role->label() }}</span></span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <x-button variant="secondary" class="self-start">Save the assessors</x-button>
                            </form>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-table caption="Every assessment" id="every-assessment">
        <x-slot:head>
            <tr>
                <th>Assessment</th>
                <th>Assessed by</th>
                <th>Form</th>
                <th>Report</th>
                <th>ODP</th>
                <th>With</th>
                <th>Due</th>
                <th>Score</th>
            </tr>
        </x-slot:head>
        @forelse ($assessments as $assessment)
            @php($a = $assessment->artefacts->keyBy(fn ($x) => $x->kind->value))
            <tr class="border-b border-surface-container last:border-0">
                <td><a href="{{ route('assessments.show', $assessment) }}" class="font-semibold text-primary underline">{{ $assessment->movement->name }}</a>
                    <span class="block text-[0.8125rem] text-on-surface-variant">{{ $assessment->period_label }} · opened by {{ $assessment->opener->name }}</span></td>
                <td class="text-[0.8125rem]">
                    @if ($assessment->workItem)
                        {{ ($assessorsOf[$assessment->movement_id] ?? collect())->pluck('name')->join(', ') ?: 'No assessor' }}
                    @else
                        <span class="text-on-surface-variant">Complete</span>
                    @endif
                </td>
                @foreach (['form', 'report', 'odp'] as $kind)
                    <td><span class="flex flex-col items-start gap-xs"><x-state-chip :state="$a[$kind]->status->effective_state" />@if ($kind === 'odp')<x-validation-chip :status="$a[$kind]->status" />@endif</span></td>
                @endforeach
                <td>{{ $assessment->workItem?->holder_role->label() ?? 'Stage 1 done' }}</td>
                <td>@if ($assessment->workItem) <x-due-chip :days="$assessment->workItem->days_left" /> @else — @endif</td>
                <td>@if ($assessment->score) <x-band-chip :band="$assessment->score->band" /> {{ number_format((float) $assessment->score->pct, 1) }}% @else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-md"><x-empty-state icon="fact_check">No assessments yet. Assign an assessor above; they open the assessment from My Work.</x-empty-state></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$assessments" fragment="every-assessment" />
</x-layouts.app>
