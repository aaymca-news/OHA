{{-- Tab 2: the health assessment report. Written outside the system and uploaded, in versions. Expects $artefact and $versions (newest first, only those this user may see). --}}
@php
    $status = $artefact->status;
    $effective = $status->effective_state;
    $latestId = $artefact->latestVersion?->id;
    $pending = $artefact->state->value === 'pending_approval';
    $seesDrafts = auth()->user()->oversees() || auth()->user()->canAssess($assessment->movement);
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
            Staff and the Board Chairperson see the last approved version until the new one is approved.
        </span>
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

@can('upload', $artefact)
    <x-card :title="$versions->isEmpty() ? 'Upload the report' : 'Upload a new version'"
            subtitle="The report written for this assessment, as a Word (.docx) or PDF file. The newest is what you submit for approval; the approved version is kept until a newer one is approved.">
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

        @if ($canFix && $shown->format->value === 'pdf' && $open->isNotEmpty())
            <p class="flex items-start gap-xs p-sm rounded bg-surface-container text-[0.875rem]">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">info</span>
                <span>This version is a PDF, which cannot be written into. Upload the report as a Word (.docx) file to fix what is flagged here; or mark items as reviewed.</span>
            </p>
        @elseif ($canFix && $fromForm->count() > 1)
            <form method="POST" action="{{ route('report-check.from-form', $artefact) }}" class="flex flex-wrap items-center gap-sm p-sm rounded bg-surface-container-low">
                @csrf
                <span class="flex-1 min-w-[min(15rem,100%)] text-[0.875rem]">{{ $fromForm->count() }} of these can be filled in from the OHA form and the assessment, in one new version.</span>
                <x-button>
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">auto_fix_high</span> Fill them in from the form</span>
                </x-button>
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
                        $input = 'px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest focus:border-primary outline-none';
                    @endphp
                    <li class="p-sm rounded border-l-4 {{ $mark ? 'border-outline-variant bg-surface-container-low' : ($finding->severity->value === 'missing' ? 'border-band-atrisk bg-serious-wash' : 'border-band-developing bg-warning-wash') }}"
                        x-data="{ open: false, note: false }">
                        <p class="text-[0.875rem] font-semibold flex flex-wrap items-center gap-xs">
                            @if ($mark)
                                <x-chip tone="good" icon="task_alt">Reviewed</x-chip>
                            @else
                                <x-chip :tone="$finding->severity->value === 'missing' ? 'serious' : 'warning'">{{ __('oha.severity.'.$finding->severity->value) }}</x-chip>
                            @endif
                            {{ $finding->message }}
                        </p>
                        @if ($mark)
                            <p class="text-[0.8125rem] mt-xs">Reviewed by {{ $mark['by_name'] }}, {{ \Illuminate\Support\Carbon::parse($mark['at'])->format('j M Y') }}: “{{ $mark['note'] }}”</p>
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
                                        <button class="inline-flex items-center gap-xs px-sm py-1 rounded border-[1.5px] border-primary text-primary font-semibold hover:bg-surface-container-lowest">
                                            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">auto_fix_high</span> Add it from the OHA form
                                        </button>
                                    </form>
                                @elseif ($writable)
                                    <button type="button" x-show="! open" x-on:click="open = true; note = false"
                                            class="inline-flex items-center gap-xs px-sm py-1 rounded border-[1.5px] border-primary text-primary font-semibold hover:bg-surface-container-lowest">
                                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit</span> Write it into the report
                                    </button>
                                @endif
                                @if ($mark)
                                    <form method="POST" action="{{ route('report-check.reopen', $artefact) }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="key" value="{{ $key }}">
                                        <button class="text-primary underline">Reopen</button>
                                    </form>
                                @else
                                    <button type="button" x-show="! note" x-on:click="note = true; open = false" class="text-primary underline">Mark as reviewed</button>
                                @endif
                            </div>

                            @if ($writable && $fix['kind'] !== 'form')
                                <form method="POST" action="{{ route('report-check.fix', $artefact) }}" x-show="open" x-cloak class="flex flex-col gap-sm mt-sm p-sm rounded bg-surface-container-lowest">
                                    @csrf
                                    <input type="hidden" name="ref" value="{{ $finding->ref }}">
                                    @if ($fix['kind'] === 'author')
                                        <label class="flex flex-col gap-xs text-[0.875rem]">
                                            <span class="font-semibold">Who wrote the report?</span>
                                            <input name="value" required maxlength="300" class="{{ $input }}"
                                                   value="{{ $assessment->movement->assessors->pluck('name')->join(' and ') }}{{ $assessment->movement->assessors->isNotEmpty() ? ', AAYMCA' : '' }}">
                                        </label>
                                    @elseif ($fix['kind'] === 'category')
                                        <label class="flex flex-col gap-xs text-[0.875rem]">
                                            <span class="font-semibold">Analysis of {{ \App\Models\Category::query()->where('code', $fix['key'])->value('name') }}</span>
                                            <textarea name="value" rows="4" required class="{{ $input }}"></textarea>
                                        </label>
                                        <label class="flex flex-col gap-xs text-[0.875rem]">
                                            <span class="font-semibold">Opportunities for growth (one per line)</span>
                                            <textarea name="growth" rows="3" required class="{{ $input }}"></textarea>
                                        </label>
                                    @else
                                        <label class="flex flex-col gap-xs text-[0.875rem]">
                                            <span class="font-semibold">{{ $fix['kind'] === 'section' ? $fix['title'] : 'Opportunities for growth (one per line)' }}</span>
                                            <textarea name="value" rows="4" required class="{{ $input }}"></textarea>
                                        </label>
                                    @endif
                                    <p class="text-[0.8125rem] flex flex-wrap gap-x-sm">
                                        <span class="inline-flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">info</span>Answer: {{ $fix['kind'] === 'author' ? 'names and roles' : 'text' }}</span>
                                        <span class="text-on-surface-variant">{{ $fix['guide'] ?? '' }}</span>
                                    </p>
                                    <p class="text-[0.8125rem] text-on-surface-variant">It is written into the report, saved as a new version made by the platform, and checked again. The approved version is kept until this one is approved.</p>
                                    <div class="flex flex-wrap items-center gap-sm">
                                        <x-button>Write it into the report</x-button>
                                        <button type="button" x-on:click="open = false" class="text-[0.875rem] text-primary underline">Cancel</button>
                                    </div>
                                </form>
                            @endif

                            @unless ($mark)
                                <form method="POST" action="{{ route('report-check.review', $artefact) }}" x-show="note" x-cloak class="flex flex-wrap items-end gap-xs mt-sm">
                                    @csrf
                                    <input type="hidden" name="key" value="{{ $key }}">
                                    <input type="hidden" name="message" value="{{ $finding->message }}">
                                    <label class="flex flex-col gap-xs text-[0.875rem] flex-1 min-w-[min(15rem,100%)]">
                                        <span class="font-semibold">What did you check?</span>
                                        <input name="note" required maxlength="2000" class="{{ $input }} py-1.5" placeholder="For example: the other YMCA is named as a partner; this is the right report.">
                                    </label>
                                    <x-button variant="secondary">Mark as reviewed</x-button>
                                    <button type="button" x-on:click="note = false" class="text-[0.875rem] text-primary underline py-2">Cancel</button>
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
