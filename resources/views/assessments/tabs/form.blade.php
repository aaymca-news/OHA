{{-- Tab 1: the OHA form. Expects $form, $upload, $uploads, $dqa, $rows, $openGaps. The form is approved by an Administrator; it is never signed. --}}
<div class="flex flex-wrap items-center gap-sm">
    <x-state-chip :state="$form->status->effective_state" />
    @if ($form->state->value === 'pending_approval')
        <span class="text-[0.875rem] text-on-surface-variant">With the Administrators for approval</span>
    @endif
    @if ($form->isApproved())
        <span class="text-[0.875rem] text-on-surface-variant">Approved by {{ $form->approver?->name }}, {{ $form->approved_at?->format('j M Y') }}</span>
    @endif
    @if (auth()->user()->isSecretariat())
    <a href="{{ route('downloads.blank-form') }}" class="ml-auto inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download the blank OHA form
    </a>
    @endif
</div>

@can('upload', $form)
    <x-card title="Upload the completed OHA form" subtitle="The .xlsx the movement returned. It is stored unchanged; the answers read from it become the record.">
        <form method="POST" action="{{ route('artefacts.upload', $form) }}" enctype="multipart/form-data"
              x-data="{ name: '', over: false }" class="flex flex-col gap-sm">
            @csrf
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
            <a href="{{ route('downloads.form', $upload) }}" class="inline-flex items-center gap-xs text-[0.875rem] text-primary underline">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span> Download this file
            </a>
        </x-slot:actions>

        @if ($form->isApproved())
            <x-score-table :rows="$rows" :total="$assessment->score" />
        @elseif ($upload->findings->where('severity', \App\Enums\FindingSeverity::Error)->isNotEmpty())
            <x-empty-state icon="block">This upload was refused, so it has no score.</x-empty-state>
        @else
            <p class="text-[0.875rem]">
                Recomputed from the answers: <strong>{{ $provisional }}</strong> points.
                @if ($upload->findings->where('rule', 'total_mismatch')->isEmpty())
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem] text-good-ink" aria-hidden="true">check_circle</span> Matches the totals printed on the form.</span>
                @endif
            </p>
            <p class="text-[0.8125rem] text-on-surface-variant">The score is recorded when an Administrator approves the form.</p>
        @endif
    </x-card>

    @can('viewGaps', $assessment)
    <x-card title="Data quality" subtitle="The same checks, grouped under the six dimensions.">
        <x-dqa-checklist :summary="$dqa" />
    </x-card>

    <x-card id="findings" title="What the check found">
        @forelse ($upload->findings->sortBy(fn ($f) => [['error' => 0, 'missing' => 1, 'warning' => 2][$f->severity->value], $f->id]) as $finding)
            <div class="flex flex-wrap items-start gap-sm p-sm rounded border-[1.5px] {{ $finding->resolved_at ? 'border-surface-container bg-surface-container-low' : 'border-outline-variant' }}">
                <x-chip :tone="['error' => 'critical', 'missing' => 'serious', 'warning' => 'warning'][$finding->severity->value]"
                        :icon="['error' => 'block', 'missing' => 'playlist_remove', 'warning' => 'visibility'][$finding->severity->value]">{{ __('oha.severity.'.$finding->severity->value) }}</x-chip>
                <div class="flex-1 min-w-60 text-[0.875rem]">
                    <p class="font-semibold">{{ $finding->message }}</p>
                    <p class="text-[0.8125rem] text-on-surface-variant">{{ $finding->location }}{{ $finding->hint ? ' · '.$finding->hint : '' }}</p>
                    @if ($finding->resolved_at)
                        <p class="text-[0.8125rem] mt-xs"><span class="font-semibold">Resolved</span> by {{ $finding->resolver->name }}, {{ $finding->resolved_at->format('j M Y') }}: “{{ $finding->resolved_note }}”</p>
                    @endif
                </div>
                @can('resolve', $finding)
                    @if ($finding->resolved_at)
                        <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                            @csrf
                            <x-button variant="secondary">Reopen</x-button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('findings.resolve', $finding) }}" class="flex flex-wrap items-end gap-xs" x-data="{ open: false }">
                            @csrf
                            <button type="button" x-show="! open" x-on:click="open = true" class="text-[0.875rem] text-primary underline">Resolve with a note</button>
                            <template x-if="open">
                                <span class="flex flex-wrap items-end gap-xs">
                                    <input name="note" required placeholder="How was it resolved?" class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant text-[0.875rem] w-64" aria-label="How was it resolved?">
                                    <x-button>Save</x-button>
                                </span>
                            </template>
                        </form>
                    @endif
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

@if ($uploads->count() > 1)
    <x-card title="Earlier uploads" subtitle="Every file is kept, unchanged.">
        <ul class="text-[0.875rem] flex flex-col gap-xs">
            @foreach ($uploads->skip(1) as $earlier)
                <li><a href="{{ route('downloads.form', $earlier) }}" class="text-primary underline">{{ $earlier->original_name }}</a>
                    <span class="text-on-surface-variant">· {{ $earlier->uploaded_at->format('j M Y H:i') }} · {{ $earlier->uploader->name }}</span></li>
            @endforeach
        </ul>
    </x-card>
@endif
