{{--
    Tab 3: the ODP. Written by the staff in Google Drive and saved here in versions, uploaded
    or taken from Google Drive; approved by an Administrator; signed by the Board Chairperson,
    which freezes it and starts Stage 2. Expects $artefact and $versions (newest first, only
    those this user may see).
--}}
@php
    $status = $artefact->status;
    $effective = $status->effective_state;
    $latestId = $artefact->latestVersion?->id;
    $shown = $versions->firstWhere('id', (int) request('version')) ?? $versions->first();
    $docs = $documents('odp');
    $signature = $artefact->signature;
    $me = auth()->user();
    $seesDrafts = $me->oversees() || $me->canAssess($assessment->movement);
    $drive = app(\App\Support\Google\GoogleDrive::class);
    $connected = $drive->configured();
@endphp

<div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
    {{-- Those who see only approved versions see the ODP as approved, not its working state. --}}
    <x-state-chip :state="$seesDrafts ? $effective : ($status->published ? 'approved' : $effective)" />
    <x-validation-chip :status="$status" />
    @if ($artefact->state->value === 'pending_approval' && $seesDrafts)
        <span class="text-on-surface-variant">Version {{ $versions->firstWhere('id', $latestId)?->number }} is with the Administrators for approval</span>
    @endif
    @if ($status->published && ! $artefact->isApproved() && ! $signature && $seesDrafts)
        <span class="flex items-center gap-xs text-on-surface-variant">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">visibility</span>
            Staff and the Board Chairperson see the last approved version until the new one is approved.
        </span>
    @endif
</div>

@if ($effective === 'locked')
    <x-empty-state icon="lock">The ODP unlocks once a version of the report is saved. The report does not need to be approved first.</x-empty-state>
@endif

@if ($signature)
    <div class="flex items-start gap-sm p-md rounded-lg bg-good-wash text-good-ink text-[0.875rem]">
        <span class="material-symbols-outlined" aria-hidden="true">verified</span>
        <p><span class="font-semibold">Signed by the Board Chairperson, so this ODP is frozen.</span>
            Version {{ $signature->document?->versionNumber() }} is the plan of record. Its implementation is followed in Stage 2.</p>
    </div>
@endif

{{-- The Google Drive document the ODP is written in: for AAYMCA staff only. --}}
@if ($artefact->drive_url && $me->isSecretariat() && $effective !== 'locked')
    <x-card title="Google Drive file" subtitle="Where the staff write the ODP together. Every saved version here is a copy of it at one moment.">
        <x-slot:actions>
            <a href="{{ $artefact->drive_url }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-xs px-md py-2 rounded bg-primary text-on-primary text-[0.875rem] font-semibold hover:opacity-90">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit_document</span> Open in Google Drive
            </a>
        </x-slot:actions>

        <dl class="grid grid-cols-[auto_1fr] gap-x-md gap-y-xs text-[0.875rem]">
            <dt class="text-on-surface-variant">Linked by</dt>
            <dd>{{ $artefact->driveLinker?->name ?? '—' }}{{ $artefact->drive_linked_at ? ', '.$artefact->drive_linked_at->format('j M Y') : '' }}</dd>
            <dt class="text-on-surface-variant">Updates</dt>
            <dd>
                @if ($signature)
                    Stopped: the signed ODP is frozen.
                @elseif (! $connected)
                    By upload only, for now. Changes in Google Drive are taken automatically once AAYMCA’s Google administrator connects the platform.
                @elseif ($artefact->drive_checked_at)
                    Taken from Google Drive automatically. Last checked {{ $artefact->drive_checked_at->diffForHumans() }}.
                @else
                    Taken from Google Drive automatically. Not checked yet.
                @endif
            </dd>
        </dl>

        @if ($artefact->drive_problem && ! $signature)
            <p class="flex items-start gap-xs p-sm rounded bg-warning-wash text-warning-ink text-[0.875rem]">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">warning</span>
                <span>{{ $artefact->drive_problem }}</span>
            </p>
        @elseif ($connected && $seesDrafts && ! $signature)
            <p class="text-[0.8125rem] text-on-surface-variant">
                The platform reads the document as {{ $drive->serviceAccountEmail() }}. Keep it in the OHA shared drive, or share it with that address (Viewer is enough).
            </p>
        @endif

        @if (Gate::allows('syncDrive', $artefact) && $connected || Gate::allows('linkDrive', $artefact))
            <div class="flex flex-wrap items-start gap-md">
                @if ($connected)
                    @can('syncDrive', $artefact)
                        <form method="POST" action="{{ route('artefacts.drive.sync', $artefact) }}">
                            @csrf
                            <x-button variant="secondary">
                                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">sync</span> Check Google Drive now</span>
                            </x-button>
                        </form>
                    @endcan
                @endif
                @can('linkDrive', $artefact)
                    <details class="flex-1 min-w-64 text-[0.875rem]">
                        <summary class="cursor-pointer font-semibold text-primary py-2">Change the link</summary>
                        <form method="POST" action="{{ route('artefacts.drive', $artefact) }}" class="flex flex-col gap-sm mt-sm">
                            @csrf
                            @method('PUT')
                            <x-input name="drive_url" label="Link to the ODP in Google Drive" type="url" :value="$artefact->drive_url" required
                                     hint="In Google Drive, open the ODP and use Share → Copy link." />
                            <x-button variant="secondary" class="self-start">Save the link</x-button>
                        </form>
                    </details>
                @endcan
            </div>
        @endif
    </x-card>
@endif

@can('upload', $artefact)
    <x-card :title="$versions->isEmpty() ? 'Upload the ODP' : 'Upload a new version'"
            :subtitle="$artefact->drive_file_id && $connected
                ? 'Changes made in Google Drive are saved as versions by themselves. Upload here only to add a version by hand, as an Excel (.xlsx), Word (.docx) or PDF file.'
                : 'The ODP as an Excel (.xlsx), Word (.docx) or PDF file, as in AAYMCA’s ODP template. Every version is kept; the newest one is what you submit for approval.'">
        <form method="POST" action="{{ route('artefacts.versions', $artefact) }}" enctype="multipart/form-data"
              x-data="{ name: '', over: false }" class="flex flex-col gap-sm">
            @csrf
            @if ($artefact->isApproved())
                <p class="p-sm rounded bg-surface-container text-[0.875rem]">This ODP is approved. A new version goes back to the Administrators for approval; until then, staff and the Board Chairperson keep seeing the approved version.</p>
            @endif
            <label x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop="over = false"
                   :class="over ? 'border-primary bg-surface-container-low' : 'border-outline-variant'"
                   class="flex flex-col items-center gap-xs p-lg rounded-lg border-2 border-dashed text-[0.875rem] cursor-pointer text-center">
                <span class="material-symbols-outlined text-[2rem] text-primary" aria-hidden="true">upload_file</span>
                <span x-text="name || 'Drop the ODP here, or choose the file (.xlsx, .docx or .pdf)'">Choose the ODP file (.xlsx, .docx or .pdf)</span>
                <input type="file" name="odp" accept=".xlsx,.docx,.pdf" required class="sr-only focus:not-sr-only"
                       x-on:change="name = $event.target.files[0]?.name ?? ''">
            </label>
            @unless ($artefact->drive_file_id)
                <x-input name="drive_url" label="Link to the ODP in Google Drive" type="url" required
                         placeholder="https://docs.google.com/spreadsheets/d/…"
                         hint="The Google Sheet (or Doc) the staff write the ODP in. In Google Drive, open it and use Share → Copy link." />
            @endunless
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

{{-- Validation: the Board Chairperson's online signature. It never holds the Secretariat back. --}}
@if ($signature)
    <x-card title="Validated by the board" :subtitle="'Signed by '.$assessment->movement->name.'’s Board Chairperson.'">
        <div class="flex flex-wrap items-end gap-lg">
            @if ($signature->isTyped())
                <p class="signature-typed min-w-48 max-w-[20rem] px-sm pt-md pb-1 border-b-2 border-on-surface text-[2rem] leading-tight text-primary break-words"
                   aria-label="Signature typed by {{ $signature->signed_name }}: {{ $signature->signature_text }}">{{ $signature->signature_text }}</p>
            @else
                <img src="{{ route('downloads.signature', $signature) }}" alt="Signature of {{ $signature->signed_name }}"
                     class="h-24 max-w-[20rem] bg-white border-b-2 border-on-surface">
            @endif
            <dl class="text-[0.875rem] grid grid-cols-[auto_1fr] gap-x-md gap-y-xs">
                <dt class="text-on-surface-variant">Signed by</dt><dd class="font-semibold">{{ $signature->signed_name }}</dd>
                <dt class="text-on-surface-variant">Role</dt><dd>{{ $signature->signer->title ?? 'Board Chairperson' }}</dd>
                <dt class="text-on-surface-variant">Date</dt><dd>{{ $signature->signed_at->format('j M Y, H:i') }}</dd>
                <dt class="text-on-surface-variant">Version signed</dt><dd>{{ $signature->document ? 'Version '.$signature->document->versionNumber().' · '.$signature->document->original_name : '—' }}</dd>
                <dt class="text-on-surface-variant">File fingerprint</dt><dd class="font-mono text-[0.8125rem]">{{ substr($signature->document_sha256, 0, 16) }}…</dd>
            </dl>
        </div>
        @if ($signature->comment)
            <p class="text-[0.875rem] border-l-4 border-outline-variant pl-sm">“{{ $signature->comment }}”</p>
        @endif
    </x-card>
@elseif (Gate::allows('sign', $artefact))
    <x-card title="Sign to validate the ODP" :subtitle="'Read version '.$artefact->approvedVersion?->versionNumber().' of the ODP below, then sign. Your signature, your name, the date and a fingerprint of this exact file are recorded. Once signed, the ODP is frozen, and so are the OHA form and the report it rests on.'">
        <form method="POST" action="{{ route('artefacts.sign', $artefact) }}" x-data="{ mark: @js(old('signature', '')) }" class="flex flex-col gap-md">
            @csrf
            <div class="flex flex-col gap-xs max-w-[28rem]">
                <x-input name="signature" label="Your signature" required maxlength="100" autocomplete="off" x-model="mark"
                         :hint="'Type your initials or your full name, for example '.collect(preg_split('/\s+/u', auth()->user()->name))->map(fn ($p) => mb_substr($p, 0, 1).'.')->implode(' ').' or '.auth()->user()->name.'.'" />
                <p class="signature-typed min-h-[3.5rem] px-sm pt-sm pb-1 border-b-2 border-on-surface text-[2rem] leading-tight text-primary break-words"
                   aria-hidden="true" x-text="mark" x-show="mark.trim() !== ''" x-cloak></p>
                <p class="text-[0.8125rem] text-on-surface-variant">Signing as {{ auth()->user()->name }}{{ auth()->user()->title ? ', '.auth()->user()->title : '' }}.</p>
            </div>
            <label class="flex flex-col gap-xs text-[0.875rem]">
                <span class="font-semibold">Comment (optional)</span>
                <textarea name="comment" rows="2" class="px-sm py-2 rounded border-[1.5px] border-outline-variant max-w-[35rem]"></textarea>
            </label>
            <label class="flex items-start gap-sm text-[0.875rem]">
                <input type="checkbox" name="confirm" value="1" required class="mt-1">
                <span>I have read this Organisational Development Plan and validate it on behalf of the {{ $assessment->movement->name }} Board.</span>
            </label>
            <x-button class="self-start">Sign and validate</x-button>
        </form>
    </x-card>
@elseif ($status->published)
    <x-empty-state icon="draw">
        @if ($me->isSecretariat())
            @if ($artefact->isApproved())
                Approved and waiting for {{ $assessment->movement->chair?->name ?? 'the Board Chairperson' }}’s signature. This does not hold up the Secretariat.
            @else
                A newer version is waiting for approval. {{ $assessment->movement->chair?->name ?? 'The Board Chairperson' }} can sign once it is approved.
            @endif
        @else
            {{ Gate::inspect('sign', $artefact)->message() }}
        @endif
    </x-empty-state>
@endif

@include('assessments.tabs._versions')

@if ($docs->isNotEmpty() || ($me->canAssess($assessment->movement) && $effective !== 'locked' && ! $signature))
    <x-card title="Reference files" subtitle="Supporting documents for the ODP. Stored once, never overwritten.">
        @foreach ($docs as $doc)
            <p class="text-[0.875rem] flex flex-wrap items-center gap-sm">
                <a href="{{ route('downloads.document', $doc) }}" class="text-primary underline inline-flex items-center gap-xs">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span>{{ $doc->original_name }}
                </a>
                <span class="text-on-surface-variant">{{ number_format($doc->size_bytes / 1024) }} KB · {{ $doc->created_at->format('j M Y') }}</span>
            </p>
        @endforeach
        @if ($me->canAssess($assessment->movement) && $effective !== 'locked' && ! $signature)
            <form method="POST" action="{{ route('artefacts.attach', $artefact) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-sm">
                @csrf
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">Attach a reference document (.docx, .pdf or .xlsx)</span>
                    <input type="file" name="document" accept=".docx,.pdf,.xlsx" required
                           class="text-[0.875rem] file:mr-sm file:px-md file:py-1.5 file:rounded file:border-[1.5px] file:border-outline-variant file:bg-surface-container-lowest file:text-primary file:font-semibold hover:file:border-primary">
                </label>
                <x-button variant="secondary">Attach</x-button>
            </form>
        @endif
    </x-card>
@endif
