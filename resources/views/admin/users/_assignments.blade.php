{{--
    What an AAYMCA user is assigned to assess: shown here, handed out on the Assessments
    page. Expects $target.
--}}
<div class="flex flex-col gap-xs text-[0.875rem]">
    <p class="font-semibold">Assigned assessments</p>
    @if ($target->assignedMovements->isEmpty())
        <p><x-chip tone="neutral" icon="assignment_late">No assessment assigned</x-chip></p>
    @else
        <ul class="flex flex-wrap gap-xs">
            @foreach ($target->assignedMovements->sortBy('name') as $movement)
                <li><a href="{{ route('movements.show', $movement) }}" class="text-primary underline">{{ $movement->name }}</a>{{ $loop->last ? '' : ',' }}</li>
            @endforeach
        </ul>
    @endif
    @if (auth()->user()->oversees())
        <a href="{{ route('assessments.index') }}#assign" class="self-start text-[0.8125rem] text-primary underline">Assign or change assessments on the Assessments page</a>
    @endif
</div>
