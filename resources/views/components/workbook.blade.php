{{--
    A workbook laid out like Excel (see App\Support\SpreadsheetPreview): one tab per sheet,
    merged cells, column widths, the file's own bold and colours, and ticked or unticked
    boxes for TRUE/FALSE. The table's heading row stays in view while scrolling. "Fit to
    width" shrinks the columns to the screen instead of keeping Excel's widths.
--}}
@props(['sheets', 'name'])
@php
    $tabs = count($sheets) > 1;
    $uid = 'wb-'.\Illuminate\Support\Str::random(6);
@endphp

<div x-data="{ sheet: 0, fit: false }" class="flex flex-col gap-sm" {{ $attributes }}>
    <div class="flex flex-wrap items-center gap-sm">
        @if ($tabs)
            <div role="tablist" aria-label="Sheets in {{ $name }}" class="flex flex-wrap gap-xs flex-1 min-w-0">
                @foreach ($sheets as $i => $sheet)
                    <button type="button" role="tab" id="{{ $uid }}-tab-{{ $i }}" aria-controls="{{ $uid }}-panel-{{ $i }}"
                            :aria-selected="sheet === {{ $i }}" :tabindex="sheet === {{ $i }} ? 0 : -1" x-on:click="sheet = {{ $i }}"
                            :class="sheet === {{ $i }} ? 'border-primary bg-primary text-on-primary' : 'border-outline-variant hover:border-primary'"
                            class="px-sm py-1 rounded border-[1.5px] text-[0.8125rem] font-semibold">{{ $sheet['title'] }}</button>
                @endforeach
            </div>
        @endif
        <label class="ml-auto inline-flex items-center gap-xs text-[0.8125rem] text-on-surface-variant">
            <input type="checkbox" x-model="fit" class="accent-primary"> Fit to width
        </label>
    </div>

    @foreach ($sheets as $i => $sheet)
        @php
            $total = array_sum($sheet['columns']);
        @endphp
        <div @if ($tabs) role="tabpanel" id="{{ $uid }}-panel-{{ $i }}" aria-labelledby="{{ $uid }}-tab-{{ $i }}" x-show="sheet === {{ $i }}" @if ($i > 0) x-cloak @endif @endif
             class="flex flex-col gap-xs">
            {{-- Relative: hidden screen-reader text in the cells stays inside the scrolling box. --}}
            <div class="workbook relative max-h-[80vh] overflow-auto rounded border-[1.5px] border-outline-variant bg-white" tabindex="0"
                 aria-label="{{ $name }}{{ $tabs ? ', sheet '.$sheet['title'] : '' }}. Scroll to read it.">
                <table class="table-fixed border-collapse text-[0.8125rem] leading-snug" style="width: {{ $total }}px"
                       :style="fit ? 'width: 100%' : 'width: {{ $total }}px'">
                    <colgroup>
                        @foreach ($sheet['columns'] as $px)
                            <col style="width: {{ $px }}px" :style="fit ? 'width: {{ round($px / $total * 100, 3) }}%' : 'width: {{ $px }}px'">
                        @endforeach
                    </colgroup>
                    <tbody>
                        @foreach ($sheet['rows'] as $row)
                            @if ($row['gap'])
                                <tr aria-hidden="true"><td colspan="{{ count($sheet['columns']) }}" class="h-2 p-0"></td></tr>
                                @continue
                            @endif
                            <tr class="align-top">
                                @foreach ($row['cells'] as $cell)
                                    @php
                                        $tag = $row['header'] ? 'th' : 'td';
                                        $css = collect([
                                            $cell['fill'] ? 'background-color: #'.$cell['fill'] : null,
                                            $cell['color'] ? 'color: #'.$cell['color'] : null,
                                        ])->filter()->implode('; ');
                                    @endphp
                                    <{{ $tag }} @if ($cell['colspan'] > 1) colspan="{{ $cell['colspan'] }}" @endif @if ($cell['rowspan'] > 1) rowspan="{{ $cell['rowspan'] }}" @endif
                                        @if ($row['header']) scope="col" @endif
                                        @if ($css !== '') style="{{ $css }}" @endif
                                        @class([
                                            'px-2 py-1 whitespace-pre-line break-words',
                                            'font-semibold' => $cell['bold'] || $row['header'],
                                            'italic' => $cell['italic'],
                                            'text-center' => $cell['align'] === 'center' || is_bool($cell['value']),
                                            'text-right' => $cell['align'] === 'right',
                                            'text-left' => $row['header'] && $cell['align'] === null,
                                            'workbook-head' => $row['header'],
                                        ])>@if (is_bool($cell['value']))<span class="material-symbols-outlined text-[1.125rem] align-middle" aria-hidden="true">{{ $cell['value'] ? 'check_box' : 'check_box_outline_blank' }}</span><span class="sr-only">{{ $cell['value'] ? 'Yes' : 'No' }}</span>@else{{ $cell['value'] }}@endif</{{ $tag }}>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($sheet['truncated'])
                <p class="text-[0.8125rem] text-on-surface-variant">Only part of this sheet is shown here. Download the file to see all of it.</p>
            @endif
        </div>
    @endforeach
</div>
