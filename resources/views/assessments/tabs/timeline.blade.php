{{-- Tab 4: the timeline. Expects $milestones and $timeline (custom steps). --}}
@php($canEdit = Gate::allows('editTimeline', $assessment))

<x-card title="Gate deadlines" subtitle="Each gate is done when its document is. A date set here replaces the default for that gate.">
    {{-- How the platform works the deadlines out: from the rules below, never from the documents. --}}
    <details class="text-[0.875rem] rounded bg-surface-container-low px-sm py-xs">
        <summary class="cursor-pointer font-semibold text-primary py-1">How these deadlines are worked out</summary>
        <div class="flex flex-col gap-xs pt-xs pb-sm">
            <p>Deadlines are not read from the uploaded documents. Each one starts from the day the assessment was opened ({{ $assessment->opened_at->format('j M Y') }}) plus a fixed number of days for that gate:</p>
            <ul class="list-disc pl-md">
                @foreach (\App\Models\Gate::query()->orderBy('sort_order')->get() as $gate)
                    <li>{{ $gate->milestone_label }}: {{ $gate->sla_days }} days after opening</li>
                @endforeach
            </ul>
            <p>The movement’s assessors or an Administrator can set a different date for any gate below; that date then counts instead. Clearing it returns the gate to its default.</p>
            <p>A gate is done the moment its document is: the OHA form, report or ODP when an Administrator first approves it, and the last gate when the Board Chairperson signs the ODP. Done after its date shows as late; not done by its date shows as overdue.</p>
            <p>When the next assessment is due is a separate rule, set by the movement’s health band: the weaker the band, the sooner it is re-assessed.</p>
        </div>
    </details>
    <div class="overflow-x-auto">
        <table class="w-full text-[0.875rem]">
            <thead class="text-left text-[0.8125rem] uppercase tracking-wider text-on-surface-variant">
                <tr><th class="py-xs pr-md">Gate</th><th class="py-xs pr-md">Due</th><th class="py-xs pr-md">Status</th>@if ($canEdit)<th class="py-xs">Change the deadline</th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($milestones as $m)
                    <tr class="border-t border-surface-container">
                        <td class="py-sm pr-md font-semibold">{{ $m->milestone_label }}</td>
                        <td class="py-sm pr-md">{{ $m->due_on->format('j M Y') }}{{ $m->set_by_hand ? ' (set by hand)' : '' }}</td>
                        <td class="py-sm pr-md">
                            @if ($m->done_at)
                                <x-chip :tone="$m->completed_late ? 'warning' : 'good'" icon="check_circle">Done {{ $m->done_at->format('j M') }}{{ $m->completed_late ? ', late' : '' }}</x-chip>
                            @elseif ($m->overdue)
                                <x-chip tone="critical" icon="alarm">Overdue</x-chip>
                            @else
                                <x-chip tone="neutral" icon="schedule">Open</x-chip>
                            @endif
                        </td>
                        @if ($canEdit)
                            <td class="py-sm">
                                @unless ($m->done_at)
                                    <form method="POST" action="{{ route('timeline.gate', $assessment) }}" class="flex flex-wrap items-center gap-xs">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="gate" value="{{ $m->gate_code }}">
                                        <input type="date" name="due_on" value="{{ $m->set_by_hand ? $m->due_on->format('Y-m-d') : '' }}" aria-label="New deadline for {{ $m->milestone_label }}"
                                               class="px-sm py-1 rounded border-[1.5px] border-outline-variant text-[0.875rem]">
                                        <x-button variant="secondary">Save</x-button>
                                    </form>
                                @endunless
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($canEdit)
        <p class="text-[0.8125rem] text-on-surface-variant">Leave the date empty and save to return a gate to its default deadline.</p>
    @endif
</x-card>

<x-card title="Other steps" subtitle="Steps staff add, such as sending the form to the movement or a field visit.">
    @forelse ($timeline as $step)
        <div class="flex flex-wrap items-center gap-sm text-[0.875rem] p-sm rounded border-[1.5px] border-outline-variant">
            <span class="flex-1 min-w-[min(10rem,100%)] {{ $step->done_on ? 'line-through text-on-surface-variant' : 'font-semibold' }}">{{ $step->label }}</span>
            @if ($canEdit)
                <form method="POST" action="{{ route('timeline.steps.update', $step) }}" class="flex flex-wrap items-center gap-xs">
                    @csrf
                    @method('PUT')
                    <input type="date" name="due_on" value="{{ $step->due_on?->format('Y-m-d') }}" aria-label="Due date for {{ $step->label }}"
                           class="px-sm py-1 rounded border-[1.5px] border-outline-variant">
                    <label class="flex items-center gap-xs"><input type="hidden" name="done" value="0"><input type="checkbox" name="done" value="1" @checked($step->done_on)> Done</label>
                    <x-button variant="secondary">Save</x-button>
                </form>
                <form method="POST" action="{{ route('timeline.steps.destroy', $step) }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">Remove</x-button>
                </form>
            @else
                <span class="text-on-surface-variant">{{ $step->due_on?->format('j M Y') ?? 'No date' }}{{ $step->done_on ? ' · done' : '' }}</span>
            @endif
        </div>
    @empty
        <x-empty-state icon="event_note">No other steps yet.</x-empty-state>
    @endforelse

    @if ($canEdit)
        <form method="POST" action="{{ route('timeline.steps.store', $assessment) }}" class="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-sm">
            @csrf
            <x-input name="label" label="New step" placeholder="Field visit" required class="sm:w-64" />
            <x-input name="due_on" label="Due" type="date" />
            <x-button class="self-start sm:self-auto">Add step</x-button>
        </form>
    @endif
</x-card>
