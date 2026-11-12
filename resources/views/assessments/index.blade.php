{{--
    The Administrators' Assessments page, in the site's newer style (design standard of
    10 Nov 2026): first who assesses each National Movement (one row each, the assessors
    changed in place), then every assessment, one row each, with where its form, report
    and ODP stand, who holds it and when they must act.
--}}
@php
    $filter = fn (bool $on) => [
        'inline-flex items-center gap-[6px] min-h-[36px] rounded-full border px-[14px] text-[0.84375rem] font-semibold',
        'bg-on-surface text-surface-container-lowest border-on-surface' => $on,
        'bg-surface-container-lowest text-on-surface-variant border-[#e7e5e0] hover:bg-surface-container-low' => ! $on,
    ];
@endphp
<x-layouts.app :title="__('oha.nav.assessments')" subtitle="Hand out the work: who assesses each National Movement. Then every assessment, who holds it now, and when they must act.">

    {{-- Who assesses each movement --}}
    <x-panel flush id="assign" title="Who assesses each National Movement"
             subtitle="A movement can have several assessors. To replace someone, untick them and tick who takes over. Everyone added or removed is told, and every change is in the audit trail.">
        <x-slot:actions>
            <nav aria-label="Which movements" class="flex flex-wrap gap-sm">
                <a href="{{ route('assessments.index').'#assign' }}" @if ($show === 'all') aria-current="page" @endif @class($filter($show === 'all'))>All · {{ $total }}</a>
                <a href="{{ route('assessments.index', ['show' => 'unassigned']).'#assign' }}" @if ($show === 'unassigned') aria-current="page" @endif @class($filter($show === 'unassigned'))>Needing an assessor · {{ $needing }}</a>
            </nav>
        </x-slot:actions>

        @if ($movements->isEmpty())
            <div class="px-lg py-lg"><x-empty-state icon="task_alt">Every movement with work to do has an assessor.</x-empty-state></div>
        @else
            <ul class="flex flex-col">
                @foreach ($movements as $movement)
                    @php
                        $current = $underWay[$movement->id] ?? null;
                        $people = $assessorsOf[$movement->id] ?? collect();
                        $open = $errors->has('action') && old('movement') === $movement->slug;
                    @endphp
                    <li id="assessors-{{ $movement->slug }}" class="scroll-mt-24 border-t border-[#f2f1ec] first:border-0" x-data="{ open: @js($open) }">
                        <div class="flex flex-wrap items-center gap-x-md gap-y-sm px-lg py-md hover:bg-[#fafaf8]">
                            <div class="flex-[999_1_14rem] min-w-0 flex flex-col gap-[2px]">
                                <a href="{{ route('movements.show', $movement) }}" class="text-[0.9375rem] font-semibold hover:underline">{{ $movement->name }}</a>
                                <span class="text-[0.8125rem] text-on-surface-variant">
                                    {{ $movement->zone->name }} ·
                                    @if ($current)
                                        <a href="{{ route('assessments.show', $current) }}" class="underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ $current->period_label }}</a> · {{ $current->workItem->holder_role->label() }}
                                    @elseif ($movement->assessments_count === 0)
                                        Never assessed
                                    @else
                                        No assessment under way
                                    @endif
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-sm">
                                @forelse ($people as $person)
                                    <span class="inline-flex items-center gap-[6px] text-[0.8125rem]"><x-avatar :user="$person" size="sm" />{{ $person->name }}</span>
                                @empty
                                    <x-status-pill :tone="$current || $movement->assessments_count === 0 ? 'warning' : 'neutral'">No assessor</x-status-pill>
                                @endforelse
                            </div>
                            <x-row-action :primary="$people->isEmpty() && ($current || $movement->assessments_count === 0)" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="assign-{{ $movement->slug }}">
                                {{ $people->isEmpty() ? 'Assign an assessor' : 'Add, replace or remove assessors' }}
                            </x-row-action>
                        </div>
                        <form id="assign-{{ $movement->slug }}" x-show="open" x-cloak method="POST" action="{{ route('movements.assessors', $movement) }}"
                              class="mx-lg mb-md flex flex-col gap-md rounded-xl border border-[#e7e5e0] bg-[#fafaf8] p-md">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="movement" value="{{ $movement->slug }}">
                            <fieldset class="flex flex-col gap-sm">
                                <legend class="text-[0.8125rem] text-on-surface-variant mb-sm">Tick everyone who should assess {{ $movement->name }}.</legend>
                                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-sm">
                                    @foreach ($staff as $person)
                                        <label class="flex items-center gap-sm rounded-[10px] border border-[#e7e5e0] bg-surface-container-lowest px-sm py-[8px] cursor-pointer has-[:checked]:border-on-surface">
                                            <input type="checkbox" name="assessor_ids[]" value="{{ $person->id }}" class="size-4 accent-[#1c1b1f]" @checked($people->contains('id', $person->id))>
                                            <span class="min-w-0">
                                                <span class="block text-[0.875rem] font-semibold truncate">{{ $person->name }}</span>
                                                <span class="block text-[0.75rem] text-on-surface-variant">{{ $person->role->label() }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="flex flex-wrap items-center gap-sm">
                                <button type="submit" class="inline-flex items-center min-h-10 px-[16px] rounded-[10px] bg-on-surface text-white text-[0.875rem] font-semibold hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Save the assessors</button>
                                <button type="button" x-on:click="open = false" class="text-[0.84375rem] font-semibold text-on-surface-variant hover:text-on-surface">Cancel</button>
                            </div>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    {{-- Every assessment --}}
    <x-panel flush id="every-assessment" title="Every assessment" subtitle="Newest first: where each document stands, who holds it now, and when they must act.">
        @if ($assessments->isEmpty())
            <div class="px-lg py-lg"><x-empty-state icon="fact_check">No assessments yet. Assign an assessor above; they open the assessment from My Work.</x-empty-state></div>
        @else
            <ul class="flex flex-col">
                @foreach ($assessments as $assessment)
                    @php($a = $assessment->artefacts->keyBy(fn ($x) => $x->kind->value))
                    <li class="flex flex-wrap items-center gap-x-lg gap-y-sm px-lg py-md border-t border-[#f2f1ec] first:border-0 hover:bg-[#fafaf8]">
                        <div class="flex-[999_1_14rem] min-w-0 flex flex-col gap-[2px]">
                            <a href="{{ route('assessments.show', $assessment) }}" class="text-[0.9375rem] font-semibold hover:underline">{{ $assessment->movement->name }} · {{ $assessment->period_label }}</a>
                            <span class="text-[0.8125rem] text-on-surface-variant">
                                Opened by {{ $assessment->opener->name }} ·
                                @if ($assessment->workItem)
                                    assessed by {{ ($assessorsOf[$assessment->movement_id] ?? collect())->pluck('name')->join(', ') ?: 'no one yet' }}
                                @else
                                    complete
                                @endif
                            </span>
                        </div>
                        <dl class="flex flex-wrap items-center gap-x-md gap-y-xs">
                            @foreach (['form', 'report', 'odp'] as $kind)
                                <div class="flex items-center gap-[6px]">
                                    <dt class="text-[0.8125rem] text-on-surface-variant">{{ __('oha.artefact.'.$kind) }}</dt>
                                    <dd><x-state-chip :state="$a[$kind]->status->effective_state" /></dd>
                                    @if ($kind === 'odp')<dd><x-validation-chip :status="$a[$kind]->status" /></dd>@endif
                                </div>
                            @endforeach
                        </dl>
                        <div class="flex flex-col items-end gap-[2px] min-w-[10rem] text-right">
                            @if ($assessment->workItem)
                                <span class="text-[0.8125rem] font-semibold">{{ $assessment->workItem->holder_role->label() }}</span>
                                <x-due-note :days="$assessment->workItem->days_left" />
                            @else
                                <x-status-pill tone="good">Stage 1 done</x-status-pill>
                            @endif
                            @if ($assessment->score)
                                <span class="text-[0.8125rem] text-on-surface-variant">{{ number_format((float) $assessment->score->pct, 1) }}% · {{ $assessment->score->band->label }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
    <x-pagination :paginator="$assessments" fragment="every-assessment" />
</x-layouts.app>
