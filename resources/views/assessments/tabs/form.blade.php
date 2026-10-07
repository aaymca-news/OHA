{{--
    Tab 1: the OHA form. Expects $form, $upload, $uploads, $previewing, $dqa, $rows, $openGaps.
    The form is approved by an Administrator; it is never signed. What it lacks can be typed
    here, in the form its question asks for; the file itself is never changed. It can be
    corrected after approval, until the Board Chairperson signs the ODP.
--}}
@use('App\Oha\Interpreter')
@use('App\Oha\FormDefinition')
@php
    $me = auth()->user();
    $frozen = $assessment->isFrozen();
    $approvedUpload = $uploads->first(fn ($u) => $u->isApproved());
    $labels = $upload?->form_meta['labels'] ?? [];
    $figure = fn ($v) => is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.') : (is_int($v) ? number_format($v) : (string) $v);
@endphp

<div class="flex flex-wrap items-center gap-sm">
    <x-state-chip :state="$form->status->effective_state" />
    @if ($form->state->value === 'pending_approval')
        <span class="text-[0.875rem] text-on-surface-variant">With the Administrators for approval</span>
    @endif
    @if ($form->isApproved())
        <span class="text-[0.875rem] text-on-surface-variant">Approved by {{ $form->approver?->name }}, {{ $form->approved_at?->format('j M Y') }}</span>
    @elseif ($approvedUpload && $upload && ! $upload->isApproved())
        <span class="flex items-center gap-xs text-[0.875rem] text-on-surface-variant">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">visibility</span>
            Corrected since approval. Everyone else sees the approved form and its score until it is approved again.
        </span>
    @endif
    @if ($me->isSecretariat())
    <a href="{{ route('downloads.blank-form') }}" class="ml-auto inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download the blank OHA form
    </a>
    @endif
</div>

@if ($frozen && ($me->oversees() || $me->canAssess($assessment->movement)))
    <div class="flex items-start gap-sm p-md rounded-lg bg-good-wash text-good-ink text-[0.875rem]">
        <span class="material-symbols-outlined" aria-hidden="true">lock</span>
        <p><span class="font-semibold">Frozen.</span> The Board Chairperson has signed the ODP, which rests on this form and the report, so none of them can be changed. Stage 2 follows the ODP’s implementation.</p>
    </div>
@endif

@can('upload', $form)
    <x-card :title="$upload ? 'Upload a corrected OHA form' : 'Upload the completed OHA form'"
            subtitle="The .xlsx the movement returned. It is stored unchanged; the answers read from it become the record.">
        <form method="POST" action="{{ route('artefacts.upload', $form) }}" enctype="multipart/form-data"
              x-data="{ name: '', over: false }" class="flex flex-col gap-sm">
            @csrf
            @if ($form->isApproved())
                <p class="p-sm rounded bg-surface-container text-[0.875rem]">This form is approved. A corrected form goes back to the Administrators, who are told; until they approve it, everyone keeps seeing the approved form and its score.</p>
            @endif
            <label x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop="over = false"
                   :class="over ? 'border-primary bg-surface-container-low' : 'border-outline-variant'"
                   class="flex flex-col items-center gap-xs p-lg rounded-lg border-2 border-dashed text-[0.875rem] cursor-pointer text-center">
                <span class="material-symbols-outlined text-[2rem] text-primary" aria-hidden="true">upload_file</span>
                <span x-text="name || 'Drop the .xlsx here, or choose the file'">Choose the .xlsx file</span>
                <input type="file" name="form" accept=".xlsx" required class="sr-only focus:not-sr-only"
                       x-on:change="name = $event.target.files[0]?.name ?? ''">
            </label>
            <x-button class="self-start">Upload and check</x-button>
        </form>
    </x-card>
@endcan

@if ($upload)
    <x-card title="Score check" :subtitle="'From '.$upload->original_name.', uploaded '.$upload->uploaded_at->format('j M Y').' by '.$upload->uploader->name.'.'">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                <a href="#form-preview" class="inline-flex items-center gap-xs text-primary underline">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">table_view</span> Preview
                </a>
                <a href="{{ route('downloads.form', $upload) }}" class="inline-flex items-center gap-xs text-primary underline">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download this file
                </a>
                @can('delete', $upload)
                    <form method="POST" action="{{ route('form-uploads.destroy', $upload) }}"
                          x-data x-on:submit="if (! confirm(@js('Delete '.$upload->original_name.'? Use this for a file uploaded by mistake. It cannot be undone.'))) $event.preventDefault()">
                        @csrf
                        @method('DELETE')
                        <button class="inline-flex items-center gap-xs text-error underline">
                            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">delete</span> Delete this upload
                        </button>
                    </form>
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
                            'c' => 'Areas of improvement, '.(\App\Models\Category::query()->where('code', $key)->value('name') ?? $key),
                            's' => 'Submission — '.$key,
                            default => $ref,
                        };
                        $shown = is_array($entry['value'])
                            ? collect($entry['value'])->map(fn ($v, $c) => $c.': '.$figure($v).'%')->implode(', ')
                            : $figure($entry['value']);
                    @endphp
                    <li class="flex flex-wrap items-start gap-sm p-sm rounded border-[1.5px] border-outline-variant">
                        <span class="flex-1 min-w-60">
                            <span class="block font-semibold">{{ $what }}</span>
                            <span class="block whitespace-pre-line">{{ $kind === 'q' && in_array(Interpreter::question($key)?->type, ['pct'], true) ? $shown.'%' : $shown }}</span>
                            <span class="block text-[0.8125rem] text-on-surface-variant">Typed by {{ $entry['by_name'] ?? 'someone' }}, {{ \Illuminate\Support\Carbon::parse($entry['at'])->format('j M Y, H:i') }}</span>
                        </span>
                        @can('supply', $upload)
                            <form method="POST" action="{{ route('form-uploads.withdraw', $upload) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="ref" value="{{ $ref }}">
                                <button class="text-[0.875rem] text-primary underline">Take back</button>
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
        @forelse ($upload->findings->sortBy(fn ($f) => [['error' => 0, 'missing' => 1, 'warning' => 2][$f->severity->value], $f->id]) as $finding)
            <div class="flex flex-wrap items-start gap-sm p-sm rounded border-[1.5px] {{ $finding->resolved_at ? 'border-surface-container bg-surface-container-low' : 'border-outline-variant' }}"
                 x-data="{ open: {{ $errors->any() && old('finding') == $finding->id ? 'true' : 'false' }}, note: false }">
                <x-chip :tone="['error' => 'critical', 'missing' => 'serious', 'warning' => 'warning'][$finding->severity->value]"
                        :icon="['error' => 'block', 'missing' => 'playlist_remove', 'warning' => 'visibility'][$finding->severity->value]">{{ __('oha.severity.'.$finding->severity->value) }}</x-chip>
                <div class="flex-1 min-w-60 text-[0.875rem]">
                    <p class="font-semibold">{{ $finding->message }}</p>
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $finding->location }}{{ $finding->hint ? ' · '.$finding->hint : '' }}</p>
                    @if ($finding->resolved_at)
                        <p class="text-[0.8125rem] mt-xs"><span class="font-semibold">Resolved</span> by {{ $finding->resolver->name }}, {{ $finding->resolved_at->format('j M Y') }}: “{{ $finding->resolved_note }}”</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
                    @can('answer', $finding)
                        <button type="button" x-show="! open" x-on:click="open = true; note = false" class="inline-flex items-center gap-xs px-sm py-1 rounded border-[1.5px] border-primary text-primary font-semibold hover:bg-surface-container-low">
                            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">edit</span> {{ $finding->severity->value === 'missing' ? 'Type the answer' : 'Correct the answer' }}
                        </button>
                    @endcan
                    @can('resolve', $finding)
                        @if ($finding->resolved_at)
                            <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                                @csrf
                                <x-button variant="secondary">Reopen</x-button>
                            </form>
                        @else
                            <button type="button" x-show="! note" x-on:click="note = true; open = false" class="text-primary underline">Resolve with a note</button>
                        @endif
                    @endcan
                </div>

                @can('resolve', $finding)
                    @unless ($finding->resolved_at)
                        <form method="POST" action="{{ route('findings.resolve', $finding) }}" x-show="note" x-cloak class="basis-full flex flex-wrap items-end gap-xs">
                            @csrf
                            <label class="flex flex-col gap-xs text-[0.875rem] flex-1 min-w-60">
                                <span class="font-semibold">How was it resolved?</span>
                                <input name="note" required class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant" placeholder="For example: the NGS confirmed by email that…">
                                <span class="text-[0.8125rem] text-on-surface-variant">A note records how it was settled; it does not change the answers or the score. To change the score, type the answer instead.</span>
                            </label>
                            <x-button>Save the note</x-button>
                            <button type="button" x-on:click="note = false" class="text-[0.875rem] text-primary underline py-2">Cancel</button>
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

@can('submit', $form)
    <x-card title="Submit for approval" subtitle="It goes to the Administrators. Any of them except you can approve it.">
        <form method="POST" action="{{ route('artefacts.submit', $form) }}" class="flex flex-col gap-sm" x-data="{ ack: false }">
            @csrf
            @if ($openGaps > 0)
                <label class="flex items-start gap-sm text-[0.875rem]">
                    <input type="hidden" name="acknowledge_gaps" value="0">
                    <input type="checkbox" name="acknowledge_gaps" value="1" x-model="ack" class="mt-1">
                    <span>I acknowledge {{ $openGaps }} open {{ $openGaps === 1 ? 'gap' : 'gaps' }} and want to proceed. They stay flagged until resolved.</span>
                </label>
                <label class="flex flex-col gap-xs text-[0.875rem]">
                    <span class="font-semibold">Reason (optional)</span>
                    <input name="reason" class="px-sm py-2 rounded border-[1.5px] border-outline-variant" value="{{ old('reason') }}">
                </label>
            @endif
            <x-button class="self-start" x-bind:disabled="{{ $openGaps > 0 ? '! ack' : 'false' }}">Submit for approval</x-button>
        </form>
    </x-card>
@endcan

@include('assessments.tabs._decisions', ['artefact' => $form])

{{-- The uploaded workbook itself, laid out like Excel. --}}
@if ($previewing)
    @php
        $workbook = \App\Support\SpreadsheetPreview::of($previewing);
    @endphp
    <x-card id="form-preview" :title="'Preview · '.$previewing->original_name"
            :subtitle="'Uploaded '.$previewing->uploaded_at->format('j M Y, H:i').' by '.$previewing->uploader->name.($previewing->id !== $upload?->id ? '. An earlier upload.' : '.').' As in the file: answers typed in the platform are not shown here.'">
        <x-slot:actions>
            <a href="{{ route('downloads.form', $previewing) }}" class="inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download
            </a>
        </x-slot:actions>
        @if ($workbook === null || $workbook === [])
            <p class="text-[0.875rem]">This file could not be shown here. Download it to read it.</p>
        @else
            <x-workbook :sheets="$workbook" :name="$previewing->original_name" />
        @endif
    </x-card>
@endif

@if ($uploads->count() > 1)
    <x-card title="All uploads" subtitle="Every file is kept unchanged, unless deleted as uploaded by mistake.">
        <ul class="flex flex-col gap-xs text-[0.875rem]">
            @foreach ($uploads as $u)
                <li @class(['flex flex-wrap items-center gap-sm p-sm rounded-lg border-[1.5px]',
                            'border-primary' => $u->id === $previewing?->id, 'border-outline-variant' => $u->id !== $previewing?->id])>
                    <span class="flex-1 min-w-48">
                        {{ $u->original_name }}
                        <span class="block text-[0.8125rem] text-on-surface-variant">{{ $u->uploaded_at->format('j M Y, H:i') }} · {{ $u->uploader->name }}{{ $u->id === $upload?->id ? ' · latest' : '' }}</span>
                    </span>
                    @if ($u->isApproved())
                        <x-chip tone="good" icon="check_circle">Approved{{ $u->approved_at ? ' · '.$u->approved_at->format('j M Y') : '' }}</x-chip>
                    @endif
                    @if ($u->id !== $previewing?->id)
                        <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'form', 'upload' => $u->id]) }}#form-preview" class="text-primary underline">Preview</a>
                    @endif
                    <a href="{{ route('downloads.form', $u) }}" class="text-primary underline">Download</a>
                    @can('delete', $u)
                        <form method="POST" action="{{ route('form-uploads.destroy', $u) }}"
                              x-data x-on:submit="if (! confirm(@js('Delete '.$u->original_name.'? It cannot be undone.'))) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <button class="text-error underline">Delete</button>
                        </form>
                    @endcan
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
