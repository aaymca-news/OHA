{{-- Who assesses the movement (AAYMCA staff only). Expects $movement, $assessors, $canAssign, $assignmentHistory, $assignedSince. --}}
<x-panel title="Assessed by" :subtitle="$canAssign ? 'Assigned on the Assessments page. Every change is in the audit trail.' : null">
    @if ($assessors->isNotEmpty())
        <ul class="flex flex-col">
            @foreach ($assessors as $person)
                <li class="flex items-center gap-sm py-[10px] border-t border-[#f0eeea] first:border-0 first:pt-0">
                    <x-avatar :user="$person" />
                    <span class="flex-1 min-w-0">
                        <span class="block text-[0.875rem] font-semibold truncate">{{ $person->name }}</span>
                        <span class="block text-[0.78125rem] text-on-surface-variant">
                            {{ $person->title ?: $person->role->label() }}{{ isset($assignedSince[$person->id]) ? ' · since '.$assignedSince[$person->id]->asDay() : '' }}
                        </span>
                    </span>
                    <x-status-pill tone="good">Current assessor</x-status-pill>
                </li>
            @endforeach
        </ul>
    @elseif ($assignmentHistory->isEmpty())
        <p class="text-[0.875rem] text-on-surface-variant">No assessor has ever been assigned to {{ $movement->name }}.</p>
    @else
        @php($last = $assignmentHistory->first())
        <p class="text-[0.875rem] text-on-surface-variant">
            Nobody is assessing {{ $movement->name }} at the moment.
            @if ($last && ($last->payload['removed_names'] ?? []) !== [])
                Last: {{ implode(', ', $last->payload['removed_names']) }}, until {{ $last->occurred_at->asDay() }}{{ ($last->payload['handed_back'] ?? false) ? ' (handed back: '.$last->payload['reason'].')' : '' }}.
            @endif
        </p>
    @endif

    @if ($canAssign)
        {{-- Assessors are handed out in one place: the Assessments page. --}}
        <a href="{{ route('assessments.index').'#assessors-'.$movement->slug }}" class="mt-md inline-flex items-center gap-[6px] text-[0.84375rem] font-semibold text-on-surface hover:underline">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">person_add</span>{{ $assessors->isEmpty() ? 'Assign an assessor' : 'Change the assessors' }} on the Assessments page
        </a>

        @if ($assignmentHistory->isNotEmpty())
            <details class="mt-md border-t border-[#f0eeea] pt-sm">
                <summary class="cursor-pointer text-[0.84375rem] font-semibold">Assignment history ({{ $assignmentHistory->count() }})</summary>
                <ol class="flex flex-col gap-sm mt-sm text-[0.8125rem]">
                    @foreach ($assignmentHistory as $event)
                        <li class="border-l-2 border-[#e4e1db] pl-sm">
                            <span class="text-on-surface-variant">{{ $event->occurred_at->asStamp() }} · {{ $event->actor?->name ?? 'System' }}</span>
                            <span class="block">
                                @if ($event->payload['handed_back'] ?? false)
                                    Stopped working on it: “{{ $event->payload['reason'] ?? '' }}”
                                @else
                                    @if (($event->payload['added_names'] ?? []) !== []) Added {{ implode(', ', $event->payload['added_names']) }}. @endif
                                    @if (($event->payload['removed_names'] ?? []) !== []) Removed {{ implode(', ', $event->payload['removed_names']) }}. @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    @endif
</x-panel>
