{{-- Tab 2: the health assessment report. Written outside the system and uploaded, in versions. Expects $artefact and $versions (newest first, only those this user may see). --}}
@php
    $status = $artefact->status;
    $effective = $status->effective_state;
    $latestId = $artefact->latestVersion?->id;
    $shown = $versions->firstWhere('id', (int) request('version')) ?? $versions->first();
    $pending = $artefact->state->value === 'pending_approval';
    $seesDrafts = auth()->user()->oversees() || auth()->user()->canAssess($assessment->movement);
@endphp

<div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
    {{-- Those who see only approved versions see the report as approved, not its working state. --}}
    <x-state-chip :state="$seesDrafts ? $effective : ($status->published ? 'approved' : $effective)" />
    @if ($pending && $seesDrafts)
        <span class="text-on-surface-variant">Version {{ $versions->firstWhere('id', $latestId)?->number }} is with the Administrators for approval</span>
    @endif
    @if ($status->published && ! $artefact->isApproved() && $seesDrafts)
        <span class="flex items-center gap-xs text-on-surface-variant">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">visibility</span>
            Staff and the Board Chairperson see the last approved version until the new one is approved.
        </span>
    @endif
</div>

@if ($effective === 'locked')
    <x-empty-state icon="lock">The report unlocks once the OHA form is uploaded and read. It does not need to be approved first.</x-empty-state>
@endif

@can('upload', $artefact)
    <x-card :title="$versions->isEmpty() ? 'Upload the report' : 'Upload a new version'"
            subtitle="The report written for this assessment, as a Word (.docx) or PDF file. Every version is kept; the newest one is what you submit for approval.">
        <form method="POST" action="{{ route('artefacts.versions', $artefact) }}" enctype="multipart/form-data"
              x-data="{ name: '', over: false }" class="flex flex-col gap-sm">
            @csrf
            @if ($artefact->isApproved())
                <p class="p-sm rounded bg-surface-container text-[0.875rem]">This report is approved. A new version goes back to the Administrators for approval; until then, staff and the Board Chairperson keep seeing the approved version.</p>
            @endif
            <label x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop="over = false"
                   :class="over ? 'border-primary bg-surface-container-low' : 'border-outline-variant'"
                   class="flex flex-col items-center gap-xs p-lg rounded-lg border-2 border-dashed text-[0.875rem] cursor-pointer text-center">
                <span class="material-symbols-outlined text-[2rem] text-primary" aria-hidden="true">upload_file</span>
                <span x-text="name || 'Drop the report here, or choose the file (.docx or .pdf)'">Choose the report file (.docx or .pdf)</span>
                <input type="file" name="report" accept=".docx,.pdf" required class="sr-only focus:not-sr-only"
                       x-on:change="name = $event.target.files[0]?.name ?? ''">
            </label>
            @if ($versions->isNotEmpty())
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">What changed in this version? (optional)</span>
                    <textarea name="note" rows="2" class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('note') }}</textarea>
                </label>
            @endif
            <x-button class="self-start">
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">save</span> Save</span>
            </x-button>
        </form>
    </x-card>
@endcan

@can('submit', $artefact)
    <form method="POST" action="{{ route('artefacts.submit', $artefact) }}" class="flex flex-wrap items-center gap-sm">
        @csrf
        <x-button>Submit version {{ $versions->firstWhere('id', $latestId)?->number }} for approval</x-button>
        <span class="text-[0.8125rem] text-on-surface-variant">Any Administrator other than you can approve it.</span>
    </form>
@endcan

@include('assessments.tabs._decisions')

{{--
    The report check: what may be missing or inaccurate in this version, against the rules
    for a report and the movement's OHA form. It only flags; nothing here stops the report
    being submitted or the next step. Seen by the movement's assessors and the Administrators.
--}}
@php
    $check = $shown ? $reportCheck($shown) : null;
    $gaps = collect($check['findings'] ?? [])->filter(fn ($f) => $f->severity->value === 'missing');
    $review = collect($check['findings'] ?? [])->reject(fn ($f) => $f->severity->value === 'missing');
    $baseline = $check['baseline'] ?? null;
@endphp
@if ($check !== null)
    <x-card id="report-check" :title="'Report check · version '.$shown->number"
            :subtitle="$check['findings'] === [] ? 'Nothing is missing, and the score agrees with the OHA form.' : count($check['findings']).' '.(count($check['findings']) === 1 ? 'item' : 'items').' to look at. None of them stops the report going forward.'">
        <x-slot:actions>
            @if ($gaps->isNotEmpty())
                <x-chip tone="serious" icon="playlist_remove">{{ $gaps->count() }} missing</x-chip>
            @endif
            @if ($review->isNotEmpty())
                <x-chip tone="warning" icon="visibility">{{ $review->count() }} to review</x-chip>
            @endif
            @if ($check['findings'] === [])
                <x-chip tone="good" icon="task_alt">No issues found</x-chip>
            @endif
        </x-slot:actions>

        <dl class="grid sm:grid-cols-2 gap-x-md gap-y-xs text-[0.875rem]">
            <dt class="text-on-surface-variant">Score the report states</dt>
            <dd class="font-semibold">{{ $check['stated']['pct'] !== null ? rtrim(rtrim(number_format($check['stated']['pct'], 2), '0'), '.').'%'.($check['stated']['out_of'] ? ' ('.rtrim(rtrim(number_format($check['stated']['points'], 2), '0'), '.').' of '.(int) $check['stated']['out_of'].')' : '') : 'Not stated' }}</dd>
            <dt class="text-on-surface-variant">Score the OHA form gives</dt>
            <dd class="font-semibold">
                @if ($baseline)
                    {{ rtrim(rtrim(number_format($baseline['points'], 2), '0'), '.') }} of {{ $baseline['available'] }} ({{ $baseline['pct'] }}%) · {{ $baseline['source'] === 'approved' ? 'approved form' : 'uploaded form, not yet approved' }}
                @else
                    No usable form yet
                @endif
            </dd>
        </dl>

        <x-dqa-checklist for="report" :summary="\App\Oha\DqaSummary::ofFindings($check['findings'])" />

        @if ($check['findings'] !== [])
            <ul class="flex flex-col gap-sm">
                @foreach ($gaps->merge($review) as $finding)
                    <li class="p-sm rounded border-l-4 {{ $finding->severity->value === 'missing' ? 'border-band-atrisk bg-serious-wash' : 'border-band-developing bg-warning-wash' }}">
                        <p class="text-[0.875rem] font-semibold flex flex-wrap items-center gap-xs">
                            <x-chip :tone="$finding->severity->value === 'missing' ? 'serious' : 'warning'">{{ __('oha.severity.'.$finding->severity->value) }}</x-chip>
                            {{ $finding->message }}
                        </p>
                        @if ($finding->hint)
                            <p class="text-[0.875rem] mt-xs">{{ $finding->hint }}</p>
                        @endif
                        @if ($finding->location)
                            <p class="text-[0.8125rem] text-on-surface-variant mt-xs">{{ $finding->location }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endif

@include('assessments.tabs._versions')
