{{--
    The versions of the report or ODP: a preview of the one chosen, and the list of all of
    them this user may see. Expects $artefact, $versions (newest first), $shown and $latestId.
--}}
@use('App\Support\VersionLabel')
@php
    $noun = $artefact->kind->value === 'odp' ? 'ODP' : 'report';
    // The live document in Google Drive is work in progress: for AAYMCA staff, not the Chairperson.
    $driveLink = $artefact->drive_url && auth()->user()->isSecretariat() ? $artefact->drive_url : null;
@endphp

@if ($shown)
    @php([$label, $icon, $tone] = VersionLabel::of($shown, $artefact, $latestId))
    <x-card :title="'Version '.$shown->number.' · '.$shown->original_name"
            :subtitle="($shown->fromDrive() ? 'Taken from Google Drive' : 'Uploaded by '.$shown->creator->name).', '.$shown->created_at->format('j M Y, H:i').' · '.number_format($shown->size_bytes / 1024).' KB'">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-sm">
                <x-chip :tone="$tone" :icon="$icon">{{ $label }}</x-chip>
                <a href="{{ route('downloads.document', $shown) }}" class="inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download
                </a>
                @if ($shown->canPreview())
                    <a href="{{ route('downloads.preview', $shown) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">open_in_new</span> Open in a new tab
                    </a>
                @endif
                @if ($driveLink)
                    <a href="{{ $driveLink }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center gap-xs px-sm py-1.5 rounded border-[1.5px] border-primary text-[0.875rem] font-semibold text-primary hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit_document</span> Continue in Google Drive
                    </a>
                @endif
            </div>
        </x-slot:actions>
        @if ($shown->fromDrive())
            <p class="text-[0.875rem] flex items-center gap-xs text-on-surface-variant">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">sync</span>
                Changed in Google Drive{{ $shown->edited_by_email ? ' by '.$shown->edited_by_email : '' }}; the platform saved it as this version.
            </p>
        @endif
        @if ($shown->note)
            <p class="text-[0.875rem] border-l-4 border-outline-variant pl-sm">{{ $shown->note }}</p>
        @endif
        @if ($shown->format->value === 'pdf')
            <iframe src="{{ route('downloads.preview', $shown) }}" title="Preview of {{ $shown->original_name }}"
                    class="w-full h-[80vh] rounded border-[1.5px] border-outline-variant bg-white"></iframe>
        @elseif ($shown->format->value === 'xlsx')
            @php($workbook = \App\Support\SpreadsheetPreview::of($shown))
            @if ($workbook === null || $workbook === [])
                <p class="text-[0.875rem]">This Excel file could not be shown here. Download it to read it.</p>
            @else
                @foreach ($workbook as $sheet)
                    <div class="flex flex-col gap-xs">
                        @if (count($workbook) > 1)
                            <h3 class="text-[0.875rem] font-semibold text-primary">Sheet: {{ $sheet['title'] }}</h3>
                        @endif
                        <div class="max-h-[80vh] overflow-auto rounded border-[1.5px] border-outline-variant bg-white">
                            <table class="text-[0.8125rem] border-collapse" aria-label="{{ $shown->original_name }}, sheet {{ $sheet['title'] }}">
                                <tbody>
                                    @foreach ($sheet['rows'] as $row)
                                        <tr class="align-top even:bg-surface-container-low">
                                            @foreach ($row as $cell)
                                                <td class="border border-outline-variant px-2 py-1 min-w-24 max-w-[24rem] whitespace-pre-line">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($sheet['truncated'])
                            <p class="text-[0.8125rem] text-on-surface-variant">Only the first rows are shown here. Download the file to see all of it.</p>
                        @endif
                    </div>
                @endforeach
            @endif
        @else
            <div x-data="docxPreview(@js(route('downloads.preview', $shown)))" class="flex flex-col gap-sm">
                <p x-show="state === 'loading'" class="text-[0.875rem] text-on-surface-variant">Loading the preview…</p>
                <p x-show="state === 'failed'" x-cloak class="text-[0.875rem]">This Word file could not be shown here. Download it to read it.</p>
                <div x-ref="page" x-show="state === 'ready'" x-cloak
                     class="docx-preview max-h-[80vh] overflow-auto rounded border-[1.5px] border-outline-variant bg-surface-container"></div>
            </div>
        @endif
    </x-card>
@elseif ($artefact->status->effective_state !== 'locked')
    <x-empty-state icon="description">
        @if (auth()->user()->canAssess($assessment->movement) || auth()->user()->oversees())
            No {{ $noun }} has been uploaded yet.
        @else
            No version of the {{ $noun }} has been approved yet.
        @endif
    </x-empty-state>
@endif

@if ($versions->count() > 1 || ($versions->isNotEmpty() && auth()->user()->isSecretariat()))
    <x-card title="Versions" subtitle="Every version is kept, never overwritten. Choose one to preview it.">
        <ul class="flex flex-col gap-xs">
            @foreach ($versions as $v)
                @php([$label, $icon, $tone] = VersionLabel::of($v, $artefact, $latestId))
                <li @class(['flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] text-[0.875rem]',
                            'border-primary bg-surface-container-lowest' => $v->id === $shown?->id,
                            'border-outline-variant' => $v->id !== $shown?->id])>
                    <span class="font-semibold text-primary">Version {{ $v->number }}</span>
                    <span class="flex-1 min-w-48">
                        {{ $v->original_name }}
                        <span class="block text-[0.8125rem] text-on-surface-variant">
                            {{ $v->fromDrive() ? 'From Google Drive'.($v->edited_by_email ? ', changed by '.$v->edited_by_email : '') : $v->creator->name }}
                            · {{ $v->created_at->format('j M Y, H:i') }}{{ $v->note ? ' · '.$v->note : '' }}
                        </span>
                    </span>
                    @if ($v->fromDrive())
                        <x-chip tone="info" icon="sync">Google Drive</x-chip>
                    @endif
                    <x-chip :tone="$tone" :icon="$icon">{{ $label }}</x-chip>
                    @if ($v->id !== $shown?->id)
                        <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => $artefact->kind->value, 'version' => $v->id]) }}" class="text-primary underline">Preview</a>
                    @endif
                    <a href="{{ route('downloads.document', $v) }}" class="text-primary underline">Download</a>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
