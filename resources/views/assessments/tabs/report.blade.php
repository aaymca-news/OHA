{{-- Tab 2: the health assessment report. Written outside the system and uploaded, in versions. Expects $artefact and $versions (newest first, only those this user may see). --}}
@php
    $status = $artefact->status;
    $effective = $status->effective_state;
    $latestId = $artefact->latestVersion?->id;
    $pending = $artefact->state->value === 'pending_approval';
    $seesDrafts = auth()->user()->can('viewDrafts', $assessment);
    // The last approved version is current for everyone; those working on it can open the newer one.
    $approvedShown = $versions->first(fn ($v) => $v->isApproved());
    $workingVersion = $seesDrafts && $approvedShown && $versions->first()?->id !== $approvedShown->id ? $versions->first() : null;
    $shown = $versions->firstWhere('id', (int) request('version'))
        ?? (request('view') === 'working' && $workingVersion ? $workingVersion : ($approvedShown ?? $versions->first()));
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
            Staff and the movement’s Board Chairperson and National General Secretary see the last approved version until the new one is approved.
        </span>
    @endif
    @if (auth()->user()->isSecretariat())
    <x-button variant="link" icon="download" :href="route('downloads.report-template')" class="ml-auto">Download the report template</x-button>
    @endif
</div>

@if ($effective === 'locked')
    <x-empty-state icon="lock">The report unlocks once the OHA form is uploaded and read. It does not need to be approved first.</x-empty-state>
@endif

@if ($workingVersion)
    @include('assessments.tabs._current-or-working', [
        'tab' => 'report',
        'working' => $shown?->id === $workingVersion->id,
        'approvedLabel' => 'version '.$approvedShown->number,
        'workingLabel' => 'version '.$workingVersion->number,
    ])
@endif

{{-- The second way to the report: drafted by the platform from the OHA form, then corrected on screen. --}}
@can('upload', $artefact)
    @php
        $reportDraft = $artefact->draft()->with('updater')->first();
    @endphp
    <x-card :title="$reportDraft ? 'Draft report' : 'Let the platform draft the report'"
            :subtitle="$reportDraft
                ? 'Drafted from the OHA form and being corrected on screen. Last saved by '.$reportDraft->updater->name.', '.$reportDraft->updated_at->diffForHumans().'.'
                : 'The platform writes every section of the AAYMCA report template from the OHA form: the overview, the scores, the key strengths, risks and opportunities, and each category’s analysis. You then correct it and add what is missing.'">
        @if ($reportDraft)
            <div class="flex flex-wrap items-center gap-sm">
                <x-button icon="edit_document" :href="route('report-draft.show', $artefact)">Open the draft</x-button>
                @if (($left = $reportDraft->toComplete()) > 0)
                    <x-chip tone="warning" icon="edit_note">{{ $left }} “To complete” left</x-chip>
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('report-draft.start', $artefact) }}">
                @csrf
                <x-button icon="auto_awesome">Generate a draft report</x-button>
            </form>
        @endif
    </x-card>
@endcan

@can('upload', $artefact)
    @include('assessments.tabs._upload-version', [
        'artefact' => $artefact,
        'action' => route('artefacts.versions', $artefact),
        'title' => $versions->isEmpty() ? 'Upload the report' : 'Upload a new version',
        'subtitle' => 'The report written for this assessment, as a Word (.docx) or PDF file. The newest is what you submit for approval; the approved version is kept until a newer one is approved.',
        'file' => 'report',
        'accept' => '.docx,.pdf',
        'fileLabel' => 'report file',
        'prompt' => 'Drop the report here, or click to choose the file (.docx or .pdf)',
        'approvedNote' => 'This report is approved. A new version goes back to the Administrators for approval; until then, staff and the movement’s Board Chairperson and National General Secretary keep seeing the approved version.',
        'askDriveLink' => false,
        'askNote' => $versions->isNotEmpty(),
        'button' => 'Save',
        'buttonIcon' => 'save',
    ])
@endcan

@include('assessments.tabs._submit', ['artefact' => $artefact, 'number' => $versions->firstWhere('id', $latestId)?->number])

@include('assessments.tabs._decisions')

{{--
    The report check: what may be missing or inaccurate in this version, against the rules
    for a report and the movement's OHA form. It only flags; nothing here stops the report
    being submitted or the next step. Seen by the movement's assessors and the Administrators.
--}}
@php
    $check = $shown ? $reportCheck($shown) : null;
    $reviewed = $artefact->reviewed_findings ?? [];
    $isReviewed = fn ($f) => isset($reviewed[\App\Oha\Report\ReportFixes::key($f)]);
    $open = collect($check['findings'] ?? [])->reject($isReviewed);
    $gaps = $open->filter(fn ($f) => $f->severity->value === 'missing');
    $review = $open->reject(fn ($f) => $f->severity->value === 'missing');
    $baseline = $check['baseline'] ?? null;
    // Fixes are written into the newest version, while the report can be changed.
    $canFix = $shown && $shown->id === $latestId && Gate::allows('fix', $artefact);
    $fromForm = collect($check['findings'] ?? [])->reject($isReviewed)->filter(fn ($f) => (\App\Oha\Report\ReportFixes::for($f)['kind'] ?? null) === 'form');
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
            <dd class="font-semibold">{{ $check['stated']['pct'] !== null ? \App\Support\Viz::figure($check['stated']['pct']).'%'.($check['stated']['out_of'] ? ' ('.\App\Support\Viz::figure($check['stated']['points']).' of '.(int) $check['stated']['out_of'].')' : '') : 'Not stated' }}</dd>
            <dt class="text-on-surface-variant">Score the OHA form gives</dt>
            <dd class="font-semibold">
                @if ($baseline)
                    {{ \App\Support\Viz::figure($baseline['points']) }} of {{ $baseline['available'] }} ({{ $baseline['pct'] }}%) · {{ $baseline['source'] === 'approved' ? 'approved form' : 'uploaded form, not yet approved' }}
                @else
                    No usable form yet
                @endif
            </dd>
        </dl>

        <x-dqa-checklist for="report" :summary="\App\Oha\DqaSummary::ofFindings($check['findings'])" />

        @if ($canFix && $shown->format->value === 'pdf' && $open->isNotEmpty())
            <x-callout tone="neutral">
                <p>This version is a PDF, which cannot be written into. Upload the report as a Word (.docx) file to fix what is flagged here; or mark items as reviewed.</p>
            </x-callout>
        @elseif ($canFix && $fromForm->count() > 1)
            <form method="POST" action="{{ route('report-check.from-form', $artefact) }}" class="flex flex-wrap items-center gap-sm p-sm rounded bg-surface-container-low">
                @csrf
                <span class="flex-1 min-w-[min(15rem,100%)] text-[0.875rem]">{{ $fromForm->count() }} of these can be filled in from the OHA form and the assessment, in one new version.</span>
                <x-button icon="auto_fix_high">Fill them in from the form</x-button>
            </form>
        @endif

        @if ($check['findings'] !== [])
            <ul class="flex flex-col gap-sm">
                @foreach (collect($check['findings'])->sortBy(fn ($f) => [$isReviewed($f) ? 1 : 0, $f->severity->value === 'missing' ? 0 : 1]) as $finding)
                    @php
                        $key = \App\Oha\Report\ReportFixes::key($finding);
                        $mark = $reviewed[$key] ?? null;
                        $fix = \App\Oha\Report\ReportFixes::for($finding);
                        $writable = $canFix && $shown->format->value === 'docx' && $fix !== null && ! $mark;
                    @endphp
                    <li class="p-sm rounded border-l-4 {{ $mark ? 'border-outline-variant bg-surface-container-low' : ($finding->severity->value === 'missing' ? 'border-band-atrisk bg-serious-wash' : 'border-band-developing bg-warning-wash') }}"
                        x-data="{ open: false, note: false }">
                        <p class="text-[0.875rem] font-semibold flex flex-wrap items-center gap-xs">
                            @if ($mark)
                                <x-chip tone="good" icon="task_alt">Reviewed</x-chip>
                            @else
                                <x-chip :tone="$finding->severity->tone()">{{ $finding->severity->label() }}</x-chip>
                            @endif
                            {{ $finding->message }}
                        </p>
                        @if ($mark)
                            <p class="text-[0.8125rem] mt-xs">Reviewed by {{ $mark['by_name'] }}, {{ \Illuminate\Support\Carbon::parse($mark['at'])->asDay() }}: “{{ $mark['note'] }}”</p>
                        @else
                            @if ($finding->hint)
                                <p class="text-[0.875rem] mt-xs">{{ $finding->hint }}</p>
                            @endif
                            @if ($finding->location)
                                <p class="text-[0.8125rem] text-on-surface-variant mt-xs">{{ $finding->location }}</p>
                            @endif
                        @endif

                        @if ($canFix)
                            <div class="flex flex-wrap items-center gap-sm mt-sm text-[0.875rem]">
                                @if ($writable && $fix['kind'] === 'form')
                                    <form method="POST" action="{{ route('report-check.fix', $artefact) }}">
                                        @csrf
                                        <input type="hidden" name="ref" value="{{ $finding->ref }}">
                                        <x-button variant="outline" size="sm" icon="auto_fix_high">Add it from the OHA form</x-button>
                                    </form>
                                @elseif ($writable)
                                    <x-button type="button" variant="outline" size="sm" icon="edit" x-show="! open" x-on:click="open = true; note = false">Write it into the report</x-button>
                                @endif
                                @if ($mark)
                                    <form method="POST" action="{{ route('report-check.reopen', $artefact) }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="key" value="{{ $key }}">
                                        <x-button variant="link">Reopen</x-button>
                                    </form>
                                @else
                                    <x-button type="button" variant="link" x-show="! note" x-on:click="note = true; open = false">Mark as reviewed</x-button>
                                @endif
                            </div>

                            @if ($writable && $fix['kind'] !== 'form')
                                <form method="POST" action="{{ route('report-check.fix', $artefact) }}" x-show="open" x-cloak class="flex flex-col gap-sm mt-sm p-sm rounded bg-surface-container-lowest">
                                    @csrf
                                    <input type="hidden" name="ref" value="{{ $finding->ref }}">
                                    {{-- One form per finding, with the same names, so plain boxes (x-input would show one's old value and error in every one). --}}
                                    @if ($fix['kind'] === 'author')
                                        <x-field label="Who wrote the report?">
                                            <input name="value" required maxlength="300" @class(\App\Support\Ui::control())
                                                   value="{{ $assessment->movement->assessors->pluck('name')->join(' and ') }}{{ $assessment->movement->assessors->isNotEmpty() ? ', AAYMCA' : '' }}">
                                        </x-field>
                                    @elseif ($fix['kind'] === 'category')
                                        <x-field :label="'Analysis of '.\App\Models\Category::nameOf($fix['key'])">
                                            <textarea name="value" rows="4" required @class(\App\Support\Ui::control())></textarea>
                                        </x-field>
                                        <x-field label="Opportunities for growth (one per line)">
                                            <textarea name="growth" rows="3" required @class(\App\Support\Ui::control())></textarea>
                                        </x-field>
                                    @else
                                        <x-field :label="$fix['kind'] === 'section' ? $fix['title'] : 'Opportunities for growth (one per line)'">
                                            <textarea name="value" rows="4" required @class(\App\Support\Ui::control())></textarea>
                                        </x-field>
                                    @endif
                                    <p class="text-[0.8125rem] flex flex-wrap gap-x-sm">
                                        <span class="inline-flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">info</span>Answer: {{ $fix['kind'] === 'author' ? 'names and roles' : 'text' }}</span>
                                        <span class="text-on-surface-variant">{{ $fix['guide'] ?? '' }}</span>
                                    </p>
                                    <p class="text-[0.8125rem] text-on-surface-variant">It is written into the report, saved as a new version made by the platform, and checked again. The approved version is kept until this one is approved.</p>
                                    <div class="flex flex-wrap items-center gap-sm">
                                        <x-button>Write it into the report</x-button>
                                        <x-button type="button" variant="link" x-on:click="open = false" class="text-[0.875rem]">Cancel</x-button>
                                    </div>
                                </form>
                            @endif

                            @unless ($mark)
                                <form method="POST" action="{{ route('report-check.review', $artefact) }}" x-show="note" x-cloak class="flex flex-wrap items-end gap-xs mt-sm">
                                    @csrf
                                    <input type="hidden" name="key" value="{{ $key }}">
                                    <input type="hidden" name="message" value="{{ $finding->message }}">
                                    <x-field label="What did you check?" class="flex-1 min-w-[min(15rem,100%)]">
                                        <input name="note" required maxlength="2000" @class(\App\Support\Ui::control(size: 'sm')) placeholder="For example: the other YMCA is named as a partner; this is the right report.">
                                    </x-field>
                                    <x-button variant="secondary">Mark as reviewed</x-button>
                                    <x-button type="button" variant="link" x-on:click="note = false" class="text-[0.875rem] py-2">Cancel</x-button>
                                </form>
                            @endunless
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endif

@include('assessments.tabs._versions')
