{{-- The newest ODP versions this user may see, and whether Google Drive is being read. Expects $odpChanges and $odpSync. --}}
@use('App\Support\VersionLabel')
@php($last = $odpSync['last'])
<x-card title="ODP changes" subtitle="The newest versions of the ODPs, uploaded or taken from Google Drive.">
    <x-slot:actions>
        @if (! $odpSync['connected'])
            <x-chip tone="neutral" icon="cloud_off">Google Drive not connected yet</x-chip>
        @elseif ($last?->error)
            <x-chip tone="critical" icon="sync_problem">Google Drive check failed</x-chip>
        @elseif ($odpSync['failing'] > 0)
            <x-chip tone="warning" icon="sync_problem">{{ $odpSync['failing'] }} {{ $odpSync['failing'] === 1 ? 'ODP' : 'ODPs' }} could not be read</x-chip>
        @elseif ($last)
            <x-chip tone="good" icon="sync">Google Drive checked {{ $last->started_at->diffForHumans() }}</x-chip>
        @else
            <x-chip tone="info" icon="sync">Google Drive connected, not checked yet</x-chip>
        @endif
    </x-slot:actions>

    @if ($odpSync['connected'] && $last?->error)
        <p class="text-[0.875rem] p-sm rounded bg-critical-wash text-critical-ink">{{ $last->error }}</p>
    @endif

    @if ($odpChanges->isEmpty())
        <x-empty-state icon="checklist">No ODP has been uploaded yet.</x-empty-state>
    @else
        <ul class="flex flex-col gap-xs">
            @foreach ($odpChanges as $v)
                @php([$label, $icon, $tone] = VersionLabel::of($v, $v->artefact, $v->artefact->latestVersion?->id))
                <li class="flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px] border-outline-variant text-[0.875rem]">
                    <a href="{{ route('assessments.show', ['assessment' => $v->artefact->assessment_id, 'tab' => 'odp', 'version' => $v->id]) }}"
                       class="font-semibold text-primary underline">{{ $v->artefact->assessment->movement->name }} · version {{ $v->number }}</a>
                    <span class="flex-1 min-w-48 text-on-surface-variant">
                        {{ $v->fromDrive() ? 'Changed in Google Drive'.($v->edited_by_email ? ' by '.$v->edited_by_email : '') : 'Uploaded by '.$v->creator->name }},
                        {{ $v->created_at->diffForHumans() }}
                    </span>
                    @if ($v->artefact->status?->validated)
                        <x-chip tone="good" icon="verified">Signed</x-chip>
                    @endif
                    <x-chip :tone="$tone" :icon="$icon">{{ $label }}</x-chip>
                </li>
            @endforeach
        </ul>
    @endif
</x-card>
