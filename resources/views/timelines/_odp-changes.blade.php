{{-- The newest ODP versions this user may see, and whether Google Drive is being read. Expects $odpChanges and $odpSync. --}}
@use('App\Support\VersionLabel')
@php($last = $odpSync['last'])
<x-panel title="ODP changes" subtitle="The newest versions of the ODPs, uploaded or taken from Google Drive" stack>
    <x-slot:actions>
        @if (! $odpSync['connected'])
            <x-status-pill>Google Drive not connected yet</x-status-pill>
        @elseif ($last?->error)
            <x-status-pill tone="critical">Google Drive check failed</x-status-pill>
        @elseif ($odpSync['failing'] > 0)
            <x-status-pill tone="warning">{{ $odpSync['failing'] }} {{ $odpSync['failing'] === 1 ? 'ODP' : 'ODPs' }} could not be read</x-status-pill>
        @elseif ($last)
            <x-status-pill tone="good">Google Drive checked {{ $last->started_at->diffForHumans() }}</x-status-pill>
        @else
            <x-status-pill tone="info">Google Drive connected, not checked yet</x-status-pill>
        @endif
    </x-slot:actions>

    @if ($odpSync['connected'] && $last?->error)
        <x-callout tone="critical" icon="sync_problem">{{ $last->error }}</x-callout>
    @endif

    @if ($odpChanges->isEmpty())
        <x-empty-state icon="checklist">No ODP has been uploaded yet.</x-empty-state>
    @else
        <ul class="flex flex-col">
            @foreach ($odpChanges as $v)
                @php([$label, $icon, $tone] = VersionLabel::of($v, $v->artefact, $v->artefact->latestVersion?->id))
                <li class="flex flex-wrap items-center gap-x-[14px] gap-y-xs py-[11px] border-t border-[#f0eeea] first:border-t-0 first:pt-0 text-[0.875rem]">
                    <span class="flex-none size-[38px] rounded-[10px] bg-[#f2f1ee] grid place-items-center text-[#4a4950]" aria-hidden="true">
                        @if ($v->fromDrive())
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18a4.5 4.5 0 01-.6-8.96A6 6 0 0118 8.5a4 4 0 01-.5 9.5H7z"/></svg>
                        @else
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/></svg>
                        @endif
                    </span>
                    <span class="flex-1 min-w-[min(14rem,100%)]">
                        <a href="{{ route('assessments.show', ['assessment' => $v->artefact->assessment_id, 'tab' => 'odp', 'version' => $v->id]) }}"
                           class="block font-semibold text-on-surface underline decoration-[#d3d0c9] underline-offset-2 hover:decoration-on-surface">{{ $v->artefact->assessment->movement->name }} · version {{ $v->number }}</a>
                        <span class="block text-[0.78125rem] text-on-surface-variant">
                            {{ $v->fromDrive() ? 'Changed in Google Drive'.($v->edited_by_email ? ' by '.$v->edited_by_email : '') : 'Uploaded by '.$v->creator->name }},
                            {{ $v->created_at->diffForHumans() }}
                        </span>
                    </span>
                    <span class="flex flex-wrap gap-xs">
                        @if ($v->artefact->status?->validated)
                            <x-status-pill tone="good">Signed</x-status-pill>
                        @endif
                        <x-status-pill :tone="$tone">{{ $label }}</x-status-pill>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-panel>
