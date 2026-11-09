{{-- Tab 5: the audit trail. Append-only: nothing here can be edited or deleted. It can be narrowed to one person. --}}
<x-card title="Audit trail" subtitle="Every step, who took it and when. The database refuses any change to these entries.">
    @if ($auditPeople->count() > 1)
        <x-slot:actions>
            <form method="GET" action="{{ route('assessments.show', $assessment) }}" class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                <input type="hidden" name="tab" value="audit">
                <label for="audit-who" class="text-on-surface-variant">Show</label>
                <select id="audit-who" name="who" onchange="this.form.submit()" class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                    <option value="">Everyone</option>
                    @foreach ($auditPeople as $person)
                        <option value="{{ $person->id }}" @selected($who === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
                <noscript><x-button>Show</x-button></noscript>
            </form>
        </x-slot:actions>
    @endif
    @if ($audit->isEmpty())
        <x-empty-state icon="history">{{ $who ? 'Nothing recorded for this person.' : 'Nothing recorded yet.' }}</x-empty-state>
    @else
        <ol class="flex flex-col gap-xs text-[0.875rem]">
            @foreach ($audit as $event)
                <li class="grid sm:grid-cols-[10rem_1fr] gap-x-md py-xs border-t border-surface-container first:border-0">
                    <span class="text-on-surface-variant tabular-nums">{{ $event->occurred_at->format('j M Y, H:i') }}</span>
                    <span>
                        <strong>{{ $event->actor?->name ?? 'System' }}</strong>
                        {{ str_replace(['.', '_'], [': ', ' '], $event->action) }}
                        @if ($event->from_state || $event->to_state)
                            <span class="text-on-surface-variant">({{ str_replace('_', ' ', (string) $event->from_state) ?: '—' }} → {{ str_replace('_', ' ', (string) $event->to_state) }})</span>
                        @endif
                        @if ($event->action === 'assessment.assessors_changed')
                            <span class="block text-[0.8125rem] text-on-surface-variant">
                                @if (($event->payload['added_names'] ?? []) !== []) Added {{ implode(', ', $event->payload['added_names']) }}. @endif
                                @if (($event->payload['removed_names'] ?? []) !== []) Removed {{ implode(', ', $event->payload['removed_names']) }}. @endif
                                Now assessing: {{ implode(', ', $event->payload['now'] ?? []) ?: 'nobody' }}.
                            </span>
                        @endif
                        @if (! empty($event->payload['replaced']))
                            <span class="block text-[0.8125rem] text-on-surface-variant">Replaced {{ $event->payload['replaced'] }}.</span>
                        @endif
                        @if (! empty($event->payload['now_current']))
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ ucfirst($event->payload['now_current']) }} is current again.</span>
                        @endif
                        @if (! empty($event->payload['reason']) || ! empty($event->payload['note']) || ! empty($event->payload['change']))
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $event->payload['reason'] ?? $event->payload['note'] ?? $event->payload['change'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</x-card>

{{-- The download log: who downloaded this assessment's files (the form, report, ODP, evidence, ZIPs), and when. --}}
<x-card title="Download log" subtitle="Who downloaded this assessment’s files, and when. Kept apart from the work above.">
    @if ($downloads->isEmpty())
        <x-empty-state icon="download">Nobody has downloaded these files yet.</x-empty-state>
    @else
        <details class="text-[0.875rem]">
            <summary class="cursor-pointer text-primary font-semibold">{{ $downloads->count() }} {{ \Illuminate\Support\Str::plural('download', $downloads->count()) }}{{ $downloads->count() === 200 ? ' (the latest 200)' : '' }}</summary>
            <ol class="flex flex-col gap-xs mt-sm">
                @foreach ($downloads as $event)
                    <li class="grid sm:grid-cols-[10rem_1fr] gap-x-md py-xs border-t border-surface-container first:border-0">
                        <span class="text-on-surface-variant tabular-nums">{{ $event->occurred_at->format('j M Y, H:i') }}</span>
                        <span><strong>{{ $event->actor?->name ?? 'System' }}</strong> downloaded <span class="break-words">{{ $event->payload['file'] ?? 'a file' }}</span></span>
                    </li>
                @endforeach
            </ol>
        </details>
    @endif
</x-card>
