{{-- Tab 5: the audit trail. Append-only: nothing here can be edited or deleted. --}}
<x-card title="Audit trail" subtitle="Every step, who took it and when. The database refuses any change to these entries.">
    @if ($audit->isEmpty())
        <x-empty-state icon="history">Nothing recorded yet.</x-empty-state>
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
                        @if (! empty($event->payload['reason']) || ! empty($event->payload['note']) || ! empty($event->payload['change']))
                            <span class="block text-[0.8125rem] text-on-surface-variant">{{ $event->payload['reason'] ?? $event->payload['note'] ?? $event->payload['change'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</x-card>
