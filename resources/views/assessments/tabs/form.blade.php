{{--
    Tab 1: the OHA form. Expects $form, $upload, $uploads, $previewing, $dqa, $rows, $openGaps.
    The form is approved by an Administrator; it is never signed. What it lacks can be typed
    here, in the form its question asks for; the file itself is never changed. It can be
    corrected after approval, until the assessment is closed.
--}}
@use('App\Oha\Interpreter')
@use('App\Oha\FormDefinition')
@php
    $me = auth()->user();
    $frozen = $assessment->isFrozen();
    $approvedUpload = $uploads->first(fn ($u) => $u->isApproved());
    $labels = $upload?->form_meta['labels'] ?? [];
    $figure = fn ($v) => is_float($v) ? \App\Support\Viz::figure($v) : (is_int($v) ? number_format($v) : (string) $v);
@endphp

<div class="flex flex-wrap items-center gap-sm">
    <x-state-chip :state="$form->status->effective_state" />
    @if ($form->state->value === 'pending_approval')
        <span class="text-[0.875rem] text-on-surface-variant">With the Administrators for approval</span>
    @endif
    @if ($form->isApproved())
        <span class="text-[0.875rem] text-on-surface-variant">Approved by {{ $form->approver?->name }}, {{ $form->approved_at?->asDay() }}</span>
    @elseif ($approvedUpload && $upload && ! $upload->isApproved())
        <span class="flex items-center gap-xs text-[0.875rem] text-on-surface-variant">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">visibility</span>
            Corrected since approval. Everyone else sees the approved form and its score until it is approved again.
        </span>
    @endif
    @if ($me->isSecretariat())
    <x-button variant="link" icon="download" :href="route('downloads.blank-form')" class="ml-auto text-[0.875rem]">Download the blank OHA form</x-button>
    @endif
</div>

@if ($workingUpload && ($me->oversees() || $me->canAssess($assessment->movement)))
    @include('assessments.tabs._current-or-working', [
        'tab' => 'form',
        'working' => $upload?->id === $workingUpload->id,
        'approvedLabel' => 'approved '.$approvedUpload?->approved_at?->asDay(),
        'workingLabel' => 'uploaded '.$workingUpload->uploaded_at->asDay(),
    ])
@endif

@if ($frozen && ($me->oversees() || $me->canAssess($assessment->movement)))
    <x-callout tone="good" icon="lock">
        <p><span class="font-semibold">Frozen.</span> The Board Chairperson has signed the ODP, which rests on this form and the report, so none of them can be changed. Stage 2 follows the ODP’s implementation.</p>
    </x-callout>
@endif

@can('upload', $form)
    @include('assessments.tabs._upload-version', [
        'artefact' => $form,
        'action' => route('artefacts.upload', $form),
        'title' => $upload ? 'Upload a corrected OHA form' : 'Upload the completed OHA form',
        'subtitle' => 'The .xlsx the movement returned. It is stored unchanged; the answers read from it become the record.',
        'file' => 'form',
        'accept' => '.xlsx',
        'fileLabel' => 'OHA form',
        'prompt' => 'Drop the .xlsx here, or click to choose the file',
        'approvedNote' => 'This form is approved. A corrected form goes back to the Administrators, who are told; until they approve it, everyone keeps seeing the approved form and its score.',
        'askDriveLink' => false,
        'askNote' => false,
        'button' => 'Upload and check',
        'buttonIcon' => null,
    ])
@endcan

@if ($upload)
    <x-card title="Score check" :subtitle="'From '.$upload->original_name.', uploaded '.$upload->uploaded_at->asDay().' by '.$upload->uploader->name.'.'">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                <x-button variant="link" icon="table_view" href="#form-preview">Preview</x-button>
                <x-button variant="link" icon="download" :href="route('downloads.form', $upload)">{{ $upload->hasEdits() ? 'Download, with the typed answers' : 'Download this file' }}</x-button>
                @if ($upload->hasEdits())
                    <a href="{{ route('downloads.form', ['formUpload' => $upload, 'original' => 1]) }}" class="text-primary underline">The file as uploaded</a>
                @endif
                @can('delete', $upload)
                    <x-confirm-form :action="route('form-uploads.destroy', $upload)" method="DELETE"
                                    :confirm="'Delete '.$upload->original_name.'? Use this for a file uploaded by mistake. It cannot be undone.'">
                        <x-button variant="link-danger" icon="delete">Delete this upload</x-button>
                    </x-confirm-form>
                @endcan
            </div>
        </x-slot:actions>

        @if ($upload->isApproved())
            <x-score-table :rows="$rows" :total="$assessment->score" />
        @elseif ($upload->findings->where('severity', \App\Enums\FindingSeverity::Error)->isNotEmpty())
            <x-empty-state icon="block">This upload was refused, so it has no score.</x-empty-state>
        @else
            <p class="text-[0.875rem]">
                Recomputed from the answers: <strong>{{ $provisional }}</strong> points.
                @if ($upload->findings->where('rule', 'total_mismatch')->isEmpty() && empty($upload->supplied))
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem] text-good-ink" aria-hidden="true">check_circle</span> Matches the totals printed on the form.</span>
                @endif
            </p>
            <p class="text-[0.8125rem] text-on-surface-variant">The score is recorded when an Administrator approves the form.{{ $approvedUpload ? ' Until then, the score recorded at the last approval stands.' : '' }}</p>
        @endif
    </x-card>

    {{-- The documents behind the form's "Yes" answers, from the movement's evidence library. --}}
    <x-card title="Evidence for the form’s answers" subtitle="Each “Yes” that a document can prove, with what the evidence library holds for it.">
        <x-slot:actions>
            <x-button variant="link" icon="folder_open" :href="route('evidence.index', ['movement' => $assessment->movement, 'assessment' => $assessment->id])" class="text-[0.875rem]">Open the evidence library</x-button>
        </x-slot:actions>
        @include('evidence._checklist', [
            'checklist' => app(\App\Queries\EvidenceLibrary::class)->checklist($upload->answers, $evidence()),
            'categoryNames' => \App\Models\Category::names(),
        ])
    </x-card>

    @can('viewGaps', $assessment)
    {{-- Answers written in words, read as what they mean; and answers typed here. --}}
    @if (! empty($upload->form_meta['interpreted']))
        <x-card title="Read from words" subtitle="These answers were written in words. They were read as shown, and that is what is recorded and scored. The file is unchanged.">
            <ul class="flex flex-col gap-xs text-[0.875rem]">
                @foreach ($upload->form_meta['interpreted'] as $code => $read)
                    <li class="flex flex-wrap items-baseline gap-x-sm">
                        <span class="font-semibold min-w-16">{{ str_replace('#2', ' (2nd)', $code) }}</span>
                        <span class="text-on-surface-variant">“{{ \Illuminate\Support\Str::limit($read['from'], 60) }}”</span>
                        <span class="material-symbols-outlined text-[1rem] text-on-surface-variant" aria-hidden="true">arrow_forward</span><span class="sr-only">read as</span>
                        <span class="font-semibold">{{ $figure($read['to']) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if (! empty($upload->supplied))
        <x-card title="Typed in the platform" subtitle="Answers the form lacked, typed here. They are recorded and scored with the form; the file itself is unchanged.">
            <ul class="flex flex-col gap-xs text-[0.875rem]">
                @foreach ($upload->supplied as $ref => $entry)
                    @php
                        [$kind, $key] = explode(':', $ref, 2) + [1 => ''];
                        $what = match ($kind) {
                            'q' => str_replace('#2', ' (2nd)', $key).' — '.\Illuminate\Support\Str::limit($labels[$key] ?? '', 70),
                            'g' => $key === 'income' ? 'Q214–Q222 Income by source' : 'Q229–Q237 Expenditure by type',
                            'c' => 'Areas of improvement, '.(\App\Models\Category::nameOf($key)),
                            's' => 'Submission — '.$key,
                            default => $ref,
                        };
                        $shown = is_array($entry['value'])
                            ? collect($entry['value'])->map(fn ($v, $c) => $c.': '.$figure($v).'%')->implode(', ')
                            : $figure($entry['value']);
                    @endphp
                    <li class="flex flex-wrap items-start gap-sm p-sm rounded border-[1.5px] border-outline-variant">
                        <span class="flex-1 min-w-[min(15rem,100%)]">
                            <span class="block font-semibold">{{ $what }}</span>
                            <span class="block whitespace-pre-line">{{ $kind === 'q' && in_array(Interpreter::question($key)?->type, ['pct'], true) ? $shown.'%' : $shown }}</span>
                            <span class="block text-[0.8125rem] text-on-surface-variant">Typed by {{ $entry['by_name'] ?? 'someone' }}, {{ \Illuminate\Support\Carbon::parse($entry['at'])->asStamp() }}</span>
                        </span>
                        @can('supply', $upload)
                            <form method="POST" action="{{ route('form-uploads.withdraw', $upload) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="ref" value="{{ $ref }}">
                                <x-button variant="link" class="text-[0.875rem]">Take back</x-button>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-card title="Data quality" subtitle="The same checks, grouped under the six dimensions.">
        <x-dqa-checklist :summary="$dqa" />
    </x-card>

    <x-card id="findings" title="What the check found">
        @forelse ($upload->findings->sortBy(fn ($f) => [$f->severity->rank(), $f->id]) as $finding)
            <div class="flex flex-wrap items-start gap-sm p-sm rounded border-[1.5px] {{ $finding->resolved_at ? 'border-surface-container bg-surface-container-low' : 'border-outline-variant' }}"
                 x-data="{ open: {{ $errors->any() && old('finding') == $finding->id ? 'true' : 'false' }}, note: false }">
                <x-chip :tone="$finding->severity->tone()" :icon="$finding->severity->icon()">{{ $finding->severity->label() }}</x-chip>
                <div class="flex-1 min-w-[min(15rem,100%)] text-[0.875rem]">
                    <p class="font-semibold">{{ $finding->message }}</p>
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $finding->location }}{{ $finding->hint ? ' · '.$finding->hint : '' }}</p>
                    @if ($why = \App\Oha\WhyItMatters::forFinding($finding))
                        <p class="text-[0.8125rem] mt-xs flex items-start gap-xs"><span class="material-symbols-outlined text-[1rem] text-primary" aria-hidden="true">lightbulb</span><span><span class="font-semibold">Why it matters:</span> {{ $why }}</span></p>
                    @endif
                    @if ($finding->resolved_at)
                        <p class="text-[0.8125rem] mt-xs"><span class="font-semibold">{{ $finding->severity->value === 'missing' ? 'Resolved' : 'Reviewed' }}</span> by {{ $finding->resolver->name }}, {{ $finding->resolved_at->asDay() }}: “{{ $finding->resolved_note }}”</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                    @can('answer', $finding)
                        <x-button type="button" variant="outline" size="sm" icon="edit" x-show="! open" x-on:click="open = true; note = false">{{ $finding->severity->value === 'missing' ? 'Type the answer' : 'Correct the answer' }}</x-button>
                    @endcan
                    @can('resolve', $finding)
                        @if ($finding->resolved_at)
                            <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                                @csrf
                                <x-button variant="secondary">Reopen</x-button>
                            </form>
                        @else
                            <x-button type="button" variant="link" x-show="! note" x-on:click="note = true; open = false">{{ $finding->severity->value === 'missing' ? 'Resolve with a note' : 'Mark as reviewed' }}</x-button>
                        @endif
                    @endcan
                </div>

                @can('resolve', $finding)
                    @unless ($finding->resolved_at)
                        <form method="POST" action="{{ route('findings.resolve', $finding) }}" x-show="note" x-cloak class="basis-full flex flex-wrap items-end gap-xs">
                            @csrf
                            {{-- One note box per finding, all named note, so not x-input (its old value and error would show in every one). --}}
                            <x-field :label="$finding->severity->value === 'missing' ? 'How was it resolved?' : 'What did you check?'" class="flex-1 min-w-[min(15rem,100%)]"
                                     hint="A note records how it was settled; it does not change the answers or the score. To change the score, type the answer instead.">
                                <input name="note" required @class(\App\Support\Ui::control(size: 'sm'))
                                       placeholder="{{ $finding->severity->value === 'missing' ? 'For example: the NGS confirmed by email that…' : 'For example: checked with the finance lead; the figures are right.' }}">
                            </x-field>
                            <x-button>Save the note</x-button>
                            <x-button type="button" variant="link" x-on:click="note = false" class="text-[0.875rem] py-2">Cancel</x-button>
                        </form>
                    @endunless
                @endcan

                @can('answer', $finding)
                    @include('assessments.tabs._answer', ['finding' => $finding, 'labels' => $labels])
                @endcan
            </div>
        @empty
            <x-empty-state icon="task_alt">Nothing is missing and nothing needs review.</x-empty-state>
        @endforelse
    </x-card>
    @endcan
@elseif (! Gate::allows('upload', $form))
    <x-empty-state icon="upload_file">No form has been uploaded yet.</x-empty-state>
@endif

{{-- An Administrator approves their own work directly (below), so is not asked to submit it. --}}
@if (Gate::allows('submit', $form) && Gate::denies('approve', $form))
    <x-card title="Submit for approval" subtitle="It goes to the Administrators to approve.">
        <form method="POST" action="{{ route('artefacts.submit', $form) }}" class="flex flex-col gap-sm" x-data="{ ack: false }">
            <input type="hidden" name="seen" value="{{ \App\Support\ReplaceGuard::current($form)?->id ?? 0 }}">
            @csrf
            @if ($openGaps > 0)
                <label class="flex items-start gap-sm text-[0.875rem]">
                    <input type="hidden" name="acknowledge_gaps" value="0">
                    <input type="checkbox" name="acknowledge_gaps" value="1" x-model="ack" class="mt-1">
                    <span>I acknowledge {{ $openGaps }} open {{ $openGaps === 1 ? 'gap' : 'gaps' }} and want to proceed. They stay flagged until resolved.</span>
                </label>
                <x-input name="reason" label="Reason (optional)" />
            @endif
            <x-button class="self-start" x-bind:disabled="{{ $openGaps > 0 ? '! ack' : 'false' }}">Submit for approval</x-button>
        </form>
    </x-card>
@endif

@include('assessments.tabs._decisions', ['artefact' => $form])

{{-- The uploaded workbook itself, laid out like Excel. --}}
@if ($previewing)
    @php
        $workbook = \App\Support\SpreadsheetPreview::of($previewing);
    @endphp
    <x-card id="form-preview" :title="'Preview · '.$previewing->original_name"
            :subtitle="'Uploaded '.$previewing->uploaded_at->asStamp().' by '.$previewing->uploader->name.($previewing->id !== $upload?->id ? '. An earlier upload.' : '.')
                .($previewing->hasEdits() ? ' Shown with the answers typed in the platform written in.' : '')">
        <x-slot:actions>
            <x-button variant="link" icon="download" :href="route('downloads.form', $previewing)" class="text-[0.875rem]">Download</x-button>
        </x-slot:actions>
        @if ($workbook === null || $workbook === [])
            <p class="text-[0.875rem]">This file could not be shown here. Download it to read it.</p>
        @else
            <x-workbook :sheets="$workbook" :name="$previewing->original_name" />
        @endif
    </x-card>
@endif

@if ($uploads->count() > 1)
    <x-card title="Uploads" subtitle="Until the ODP is signed, only the approved upload and the newest are kept; the audit trail records every one.">
        <ul class="flex flex-col gap-xs text-[0.875rem]">
            @foreach ($uploads as $u)
                <li @class(['flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px]',
                            'border-primary' => $u->id === $previewing?->id, 'border-outline-variant' => $u->id !== $previewing?->id])>
                    <span class="flex-1 min-w-[min(12rem,100%)]">
                        {{ $u->original_name }}
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $u->uploaded_at->asStamp() }} · {{ $u->uploader->name }}{{ $u->id === $upload?->id ? ' · latest' : '' }}</span>
                    </span>
                    @if ($u->isApproved())
                        <x-chip tone="good" icon="check_circle">Approved{{ $u->approved_at ? ' · '.$u->approved_at->asDay() : '' }}</x-chip>
                    @endif
                    @if ($u->id !== $previewing?->id)
                        <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'form', 'upload' => $u->id]) }}#form-preview" class="text-primary underline">Preview</a>
                    @endif
                    <a href="{{ route('downloads.form', $u) }}" class="text-primary underline">Download</a>
                    @can('delete', $u)
                        <x-confirm-form :action="route('form-uploads.destroy', $u)" method="DELETE" :confirm="'Delete '.$u->original_name.'? It cannot be undone.'">
                            <x-button variant="link-danger">Delete</x-button>
                        </x-confirm-form>
                    @endcan
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
