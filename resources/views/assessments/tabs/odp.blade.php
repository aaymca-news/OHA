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
    $signature = $artefact->signature;
    $me = auth()->user();
    $seesDrafts = $me->can('viewDrafts', $assessment);
    // The last approved version is current for everyone; those working on it can open the newer one.
    $approvedShown = $versions->first(fn ($v) => $v->isApproved());
    $workingVersion = $seesDrafts && $approvedShown && $versions->first()?->id !== $approvedShown->id ? $versions->first() : null;
    $shown = $versions->firstWhere('id', (int) request('version'))
        ?? (request('view') === 'working' && $workingVersion ? $workingVersion : ($approvedShown ?? $versions->first()));
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
            Staff and the movement’s Board Chairperson and National General Secretary see the last approved version until the new one is approved.
        </span>
    @endif
    @if ($me->isSecretariat())
    <x-button variant="link" icon="download" :href="route('downloads.odp-template')" class="ml-auto">Download the ODP template</x-button>
    @endif
</div>

@if ($effective === 'locked')
    <x-empty-state icon="lock">The ODP unlocks once a version of the report is saved. The report does not need to be approved first.</x-empty-state>
@endif

@if ($workingVersion && ! $signature)
    @include('assessments.tabs._current-or-working', [
        'tab' => 'odp',
        'working' => $shown?->id === $workingVersion->id,
        'approvedLabel' => 'version '.$approvedShown->number,
        'workingLabel' => 'version '.$workingVersion->number,
    ])
@endif

{{-- The link to Google Drive no longer works: said at the top, with what to do. --}}
@if ($artefact->drive_problem && $me->isSecretariat())
    <x-callout tone="serious" icon="link_off" alert title="The ODP’s Google Drive link is not working">
        <p>{{ $artefact->drive_problem }}</p>
        <p class="text-[0.8125rem]">If the document was moved to another folder, the link still works once it is shared again. If the staff now work in a new copy, change the link below so the platform reads that copy.</p>
    </x-callout>
@endif

@if ($signature)
    <x-callout tone="good" icon="verified">
        <p><span class="font-semibold">Signed by the Board Chairperson, so this ODP is frozen.</span>
            Version {{ $signature->document?->versionNumber() }} is the plan of record. Its implementation is followed in Stage 2.</p>
    </x-callout>
@endif

{{-- The Google Drive document the ODP is written in: for AAYMCA staff only. --}}
@if ($artefact->drive_url && $me->isSecretariat() && $effective !== 'locked')
    <x-card title="Google Drive file" subtitle="Where the staff write the ODP together. Every saved version here is a copy of it at one moment.">
        <x-slot:actions>
            <x-button icon="edit_document" :href="$artefact->drive_url" target="_blank" rel="noopener noreferrer">Open in Google Drive</x-button>
        </x-slot:actions>

        <dl class="grid grid-cols-[auto_1fr] gap-x-md gap-y-xs text-[0.875rem]">
            <dt class="text-on-surface-variant">Linked by</dt>
            <dd>{{ $artefact->driveLinker?->name ?? '—' }}{{ $artefact->drive_linked_at ? ', '.$artefact->drive_linked_at->asDay() : '' }}</dd>
            <dt class="text-on-surface-variant">Updates</dt>
            <dd>
                @if ($signature && $connected)
                    The signed ODP is frozen. Changes made in Google Drive are still read, and noted below as changes after signing, never as versions.
                @elseif ($signature)
                    The signed ODP is frozen. Once AAYMCA’s Google administrator connects the platform, changes made in Google Drive are noted below.
                @elseif (! $connected)
                    By upload only, for now. Changes in Google Drive are taken automatically once AAYMCA’s Google administrator connects the platform.
                @elseif ($artefact->drive_checked_at)
                    Taken from Google Drive automatically. Last checked {{ $artefact->drive_checked_at->diffForHumans() }}.
                @else
                    Taken from Google Drive automatically. Not checked yet.
                @endif
            </dd>
        </dl>

        @if ($artefact->drive_problem)
            <x-callout tone="warning">
                <p>{{ $artefact->drive_problem }}</p>
            </x-callout>
        @elseif ($connected && $seesDrafts)
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
                            <x-button variant="secondary" icon="sync">Check Google Drive now</x-button>
                        </form>
                    @endcan
                @endif
                @can('linkDrive', $artefact)
                    <details class="flex-1 min-w-[min(16rem,100%)] text-[0.875rem]">
                        <summary class="cursor-pointer font-semibold text-primary py-2">Change the link</summary>
                        <form method="POST" action="{{ route('artefacts.drive', $artefact) }}" class="flex flex-col gap-sm mt-sm">
                            @csrf
                            @method('PUT')
                            <div x-data="driveLink(@js(old('drive_url', $artefact->drive_url)))" class="flex flex-col gap-xs">
                                <x-input name="drive_url" label="Link to the ODP in Google Drive" type="url" :value="$artefact->drive_url" required x-on:input="link = $event.target.value"
                                         hint="The ODP file itself, not its folder. In Google Drive, open the ODP and use Share → Copy link." />
                                <p x-show="problem" x-cloak role="alert" class="flex items-start gap-xs text-[0.875rem] text-error">
                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">error</span><span x-text="problem"></span>
                                </p>
                            </div>
                            <x-button variant="secondary" class="self-start">Save the link</x-button>
                        </form>
                    </details>
                @endcan
            </div>
        @endif
    </x-card>
@endif

@can('upload', $artefact)
    @include('assessments.tabs._upload-version', [
        'artefact' => $artefact,
        'action' => route('artefacts.versions', $artefact),
        'title' => $versions->isEmpty() ? 'Upload the ODP' : 'Upload a new version',
        'subtitle' => $artefact->drive_file_id && $connected
            ? 'Changes made in Google Drive are saved as versions by themselves. Upload here only to add a version by hand, as an Excel (.xlsx), Word (.docx) or PDF file.'
            : 'The ODP as an Excel (.xlsx), Word (.docx) or PDF file, as in AAYMCA’s ODP template. The newest is what you submit for approval; the approved version is kept until a newer one is approved.',
        'file' => 'odp',
        'accept' => '.xlsx,.docx,.pdf',
        'fileLabel' => 'ODP file',
        'prompt' => 'Drop the ODP here, or click to choose the file (.xlsx, .docx or .pdf)',
        'approvedNote' => 'This ODP is approved. A new version goes back to the Administrators for approval; until then, staff and the movement’s Board Chairperson and National General Secretary keep seeing the approved version.',
        'askDriveLink' => ! $artefact->drive_file_id,
        'askNote' => $versions->isNotEmpty(),
        'button' => 'Save',
        'buttonIcon' => 'save',
    ])
@endcan

@include('assessments.tabs._submit', ['artefact' => $artefact, 'number' => $versions->firstWhere('id', $latestId)?->number])

@include('assessments.tabs._decisions')

{{-- Validation: the Board Chairperson's online signature. It never holds the Secretariat back. --}}
@if ($signature)
    <x-card title="Validated by the board" :subtitle="'Signed by '.$assessment->movement->name.'’s Board Chairperson.'">
        <div class="flex flex-wrap items-end gap-lg">
            @if ($signature->isTyped())
                <p class="signature-typed min-w-[min(12rem,100%)] max-w-full sm:max-w-[20rem] px-sm pt-md pb-1 border-b-2 border-on-surface text-[2rem] leading-tight text-primary break-words"
                   aria-label="Signature typed by {{ $signature->signed_name }}: {{ $signature->signature_text }}">{{ $signature->signature_text }}</p>
            @else
                <img src="{{ route('downloads.signature', $signature) }}" alt="Signature of {{ $signature->signed_name }}"
                     class="h-24 max-w-[20rem] bg-white border-b-2 border-on-surface">
            @endif
            <dl class="text-[0.875rem] grid grid-cols-[auto_1fr] gap-x-md gap-y-xs">
                <dt class="text-on-surface-variant">Signed by</dt><dd class="font-semibold">{{ $signature->signed_name }}</dd>
                <dt class="text-on-surface-variant">Role</dt><dd>{{ $signature->signer->title ?? 'Board Chairperson' }}</dd>
                <dt class="text-on-surface-variant">Date</dt><dd>{{ $signature->signed_at->asStamp() }}</dd>
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
            <x-textarea name="comment" label="Comment (optional)" rows="2" class="max-w-[35rem]" />
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

{{-- Stage 2: the signed ODP as changed in Google Drive since; noted, never versions. --}}
@if ($signature)
    @php
        $after = $artefact->changesAfterSigning()->with('creator')->get()->reverse()->values();
    @endphp
    <x-card id="after-signing" title="Changes made after signing"
            :subtitle="$after->isEmpty()
                ? 'None yet. Changes made to the ODP in Google Drive after the Board Chairperson signed are noted here, with what changed. The signed version stays the plan of record.'
                : 'Noted from Google Drive since the Board Chairperson signed. The signed version (version '.$signature->document?->versionNumber().') stays the plan of record.'">
        @if ($after->isNotEmpty())
            <ul class="flex flex-col gap-sm text-[0.875rem]">
                @foreach ($after as $change)
                    @php
                        $c = $change->changes ?? ['kind' => 'file', 'count' => 1];
                        $what = match ($c['kind'] ?? 'file') {
                            'cells' => $c['count'].' '.($c['count'] === 1 ? 'cell changed' : 'cells changed'),
                            'lines' => $c['count'].' '.($c['count'] === 1 ? 'line changed' : 'lines changed'),
                            default => 'The file changed',
                        };
                    @endphp
                    <li class="p-sm rounded-lg border-[1.5px] border-outline-variant flex flex-col gap-xs" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center gap-sm">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">history_edu</span>
                            <span class="flex-1 min-w-[min(12rem,100%)]">
                                <span class="font-semibold">{{ $change->created_at->asStamp() }}</span> · {{ $what }}
                                <span class="block text-[0.8125rem] text-on-surface-variant">Changed in Google Drive{{ $change->edited_by_email ? ' by '.$change->edited_by_email : '' }}</span>
                            </span>
                            @if (in_array($c['kind'] ?? 'file', ['cells', 'lines'], true) && $c['count'] > 0)
                                <x-button type="button" variant="link" x-on:click="open = ! open" ::aria-expanded="open">What changed</x-button>
                            @endif
                            <x-button variant="link" icon="download" :href="route('downloads.document', $change)">Download this copy</x-button>
                        </div>
                        @if (($c['kind'] ?? '') === 'cells')
                            <div x-show="open" x-cloak class="relative overflow-x-auto">
                                <table class="w-full text-[0.8125rem]">
                                    <thead class="text-left text-on-surface-variant"><tr><th class="py-1 pr-sm">Sheet · cell</th><th class="py-1 pr-sm">Before</th><th class="py-1">After</th></tr></thead>
                                    <tbody>
                                        @foreach ($c['cells'] as $cell)
                                            <tr class="border-t border-surface-container align-top">
                                                <td class="py-1 pr-sm whitespace-nowrap">{{ $cell['sheet'] }} · {{ $cell['cell'] }}</td>
                                                <td class="py-1 pr-sm text-on-surface-variant break-words">{{ $cell['before'] !== '' ? $cell['before'] : '(empty)' }}</td>
                                                <td class="py-1 font-semibold break-words">{{ $cell['after'] !== '' ? $cell['after'] : '(empty)' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if ($c['truncated'] ?? false)
                                    <p class="text-[0.8125rem] text-on-surface-variant mt-xs">Only the first changes are listed. Download the copy to see all of it.</p>
                                @endif
                            </div>
                        @elseif (($c['kind'] ?? '') === 'lines')
                            <div x-show="open" x-cloak class="flex flex-col gap-xs text-[0.8125rem]">
                                @foreach ($c['added'] as $line)
                                    <p class="pl-sm border-l-4 border-band-strong">Added: {{ $line }}</p>
                                @endforeach
                                @foreach ($c['removed'] as $line)
                                    <p class="pl-sm border-l-4 border-band-critical text-on-surface-variant">Removed: {{ $line }}</p>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endif

@include('assessments.tabs._versions')

{{-- The evidence linked to the ODP's tasks, from the movement's evidence library. --}}
@php($byTask = app(\App\Queries\EvidenceLibrary::class)->byOdpTask($evidence()))
<x-card title="Evidence for the ODP tasks" subtitle="Supporting documents linked to the ODP’s tasks (1.1 to 9.3), from the movement’s evidence library.">
    <x-slot:actions>
        <x-button variant="link" icon="folder_open" :href="route('evidence.index', ['movement' => $assessment->movement, 'assessment' => $assessment->id])" class="text-[0.875rem]">Open the evidence library</x-button>
    </x-slot:actions>
    @forelse ($byTask as $task => $documents)
        <div class="flex flex-wrap items-baseline gap-x-sm gap-y-xs text-[0.875rem]">
            <span class="font-semibold min-w-16">Task {{ $task }}</span>
            @foreach ($documents as $doc)
                <x-evidence-link :doc="$doc" />
            @endforeach
        </div>
    @empty
        <x-empty-state icon="attach_file">No document is linked to an ODP task yet. Add evidence in the evidence library and choose the tasks it supports.</x-empty-state>
    @endforelse
</x-card>
