{{-- Who assesses the movement. Expects $movement, $assessors, $staff, $canAssign, $assignmentHistory, $assignedSince. --}}
<x-card title="Assessed by" :subtitle="$canAssign ? 'Only the Administrators assign or reassign assessors. Every change is in the audit trail.' : null">
    @if ($assessors->isNotEmpty())
        <ul class="flex flex-col gap-xs">
            @foreach ($assessors as $person)
                <li class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                    <x-avatar :user="$person" />
                    <span class="flex-1 min-w-40">
                        <span class="font-semibold">{{ $person->name }}</span>
                        <span class="block text-[0.8125rem] text-on-surface-variant">
                            {{ $person->role->label() }}{{ $person->title ? ' · '.$person->title : '' }}{{ isset($assignedSince[$person->id]) ? ' · since '.$assignedSince[$person->id]->format('j M Y') : '' }}
                        </span>
                    </span>
                    <x-chip tone="good" icon="person_check">Current assessor</x-chip>
                </li>
            @endforeach
        </ul>
    @elseif ($assignmentHistory->isEmpty())
        <x-empty-state icon="person_off">No assessor has ever been assigned to {{ $movement->name }}.</x-empty-state>
    @else
        @php($last = $assignmentHistory->first())
        <x-empty-state icon="person_off">
            Nobody is assessing {{ $movement->name }} at the moment.
            @if ($last && ($last->payload['removed_names'] ?? []) !== [])
                Last: {{ implode(', ', $last->payload['removed_names']) }}, until {{ $last->occurred_at->format('j M Y') }}{{ ($last->payload['handed_back'] ?? false) ? ' (handed back: '.$last->payload['reason'].')' : '' }}.
            @endif
        </x-empty-state>
    @endif

    @if ($canAssign)
        <details class="border-t border-surface-container pt-sm" @if ($assessors->isEmpty()) open @endif>
            <summary class="cursor-pointer text-[0.875rem] font-semibold text-primary">
                {{ $assessors->isEmpty() ? 'Assign an assessor' : 'Reassign the assessor' }}
            </summary>
            <form method="POST" action="{{ route('movements.assessors', $movement) }}" class="flex flex-col gap-sm mt-sm">
                @csrf
                @method('PUT')
                <fieldset class="flex flex-col gap-xs text-[0.875rem] max-h-64 overflow-y-auto">
                    <legend class="text-[0.8125rem] text-on-surface-variant mb-xs">Tick who should assess {{ $movement->name }}. Untick someone to take them off.</legend>
                    @foreach ($staff as $person)
                        <label class="flex items-center gap-sm">
                            <input type="checkbox" name="assessor_ids[]" value="{{ $person->id }}" @checked($assessors->contains('id', $person->id))>
                            {{ $person->name }} <span class="text-[0.8125rem] text-on-surface-variant">{{ $person->role->label() }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <x-button variant="secondary" class="self-start">Save assessors</x-button>
            </form>
        </details>

        @if ($assignmentHistory->isNotEmpty())
            <details class="border-t border-surface-container pt-sm">
                <summary class="cursor-pointer text-[0.875rem] font-semibold text-primary">Assignment history ({{ $assignmentHistory->count() }})</summary>
                <ol class="flex flex-col gap-xs mt-sm text-[0.8125rem]">
                    @foreach ($assignmentHistory as $event)
                        <li class="border-l-4 border-outline-variant pl-sm">
                            <span class="text-on-surface-variant">{{ $event->occurred_at->format('j M Y, H:i') }} · {{ $event->actor?->name ?? 'System' }}</span>
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
</x-card>
