{{--
    Resources as a table: one row per document (approved form, report and ODP, and evidence),
    sorted by the column clicked. Expects $rows, $filters and $here (the page's URL with
    changes).
--}}
@php($sortLink = fn (string $column) => $here(['sort' => $column, 'dir' => $filters['sort'] === $column && $filters['dir'] === 'asc' ? 'desc' : 'asc']))
@if ($rows->isEmpty())
    <x-empty-state icon="table_rows">No document matches these filters.</x-empty-state>
@else
    <p class="text-[0.8125rem] text-on-surface-variant">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('document', $rows->count()) }}. Click a column heading to sort.</p>
    <x-table class="min-w-[52rem]">
        <x-slot:head>
            <tr>
                @foreach (['movement' => 'Movement', 'zone' => 'Zone', 'period' => 'Assessment', 'type' => 'Document'] as $column => $label)
                    <th @if ($filters['sort'] === $column) aria-sort="{{ $filters['dir'] === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        <a href="{{ $sortLink($column) }}" class="inline-flex items-center gap-xs hover:text-on-surface">
                            {{ $label }}
                            @if ($filters['sort'] === $column)
                                <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $filters['dir'] === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>
                            @endif
                        </a>
                    </th>
                @endforeach
                <th>Name</th>
                <th @if ($filters['sort'] === 'date') aria-sort="{{ $filters['dir'] === 'asc' ? 'ascending' : 'descending' }}" @endif>
                    <a href="{{ $sortLink('date') }}" class="inline-flex items-center gap-xs hover:text-on-surface">
                        Date
                        @if ($filters['sort'] === 'date')
                            <span class="material-symbols-outlined text-[1rem]" aria-hidden="true">{{ $filters['dir'] === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>
                        @endif
                    </a>
                </th>
                <th>Status</th>
                <th><span class="sr-only">Open</span></th>
            </tr>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr class="border-t border-[#f2f1ec] hover:bg-[#fafaf8]">
                <td class="font-semibold">{{ $row['movement']->name }}</td>
                <td>{{ $row['movement']->zone->name }}</td>
                <td class="whitespace-nowrap">{{ $row['assessment']->period_label }}</td>
                <td>{{ $row['type'] }}</td>
                <td class="break-words max-w-[18rem]">{{ $row['name'] }}</td>
                <td class="whitespace-nowrap tabular-nums">{{ $row['date']?->asDay() ?? '—' }}</td>
                <td>
                    @if ($row['status'] === 'Validated')
                        <x-status-pill tone="good">Validated</x-status-pill>
                    @elseif ($row['status'] === 'Not validated')
                        <x-status-pill>Not validated</x-status-pill>
                    @else
                        {{ $row['status'] }}
                    @endif
                </td>
                <td class="whitespace-nowrap">
                    <span class="flex items-center gap-sm">
                        @if ($row['read'])
                            <a href="{{ $row['read'] }}" class="inline-flex items-center min-h-8 rounded-[8px] px-[10px] text-[0.8125rem] font-semibold hover:bg-surface-container-low">Open</a>
                        @endif
                        <a href="{{ $row['download'] }}" class="grid size-8 place-items-center rounded-[8px] text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface" @if ($row['status'] === 'Link') target="_blank" rel="noopener noreferrer" @endif
                           aria-label="{{ $row['status'] === 'Link' ? 'Open the link' : 'Download' }}: {{ $row['name'] }}">
                            <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true">{{ $row['status'] === 'Link' ? 'open_in_new' : 'download' }}</span>
                        </a>
                    </span>
                </td>
            </tr>
        @endforeach
    </x-table>
@endif
