{{--
    The report drafted from the OHA form, corrected section by section. Expects $artefact
    (the report), $assessment, $draft, $formChanged, and the form's $labels and $answers
    (shown beside each section, so the reviewer sees why it says what it says).
--}}
@php
    // The boxes of each section's form: plain controls with the shared classes, not x-input or
    // x-textarea, since every section's form uses the same names (an old value or error would
    // show in all of them).
    $box = [...\App\Support\Ui::control(), 'w-full text-[0.9375rem] leading-relaxed' => true];
    $rows = fn (string $text) => max(3, min(18, (int) ceil(mb_strlen($text) / 95) + substr_count($text, "\n") + 1));
    // "Place it after": every section but the part headings; the last is chosen.
    $places = collect($draft->sections)->reject(fn ($s) => $s['kind'] === 'heading')
        ->mapWithKeys(fn ($s) => [$s['key'] => $s['kind'] === 'title' ? 'Title' : $s['title']])->all();
    $toComplete = $draft->toComplete();
    $anchor = fn (string $key) => str_replace(':', '-', $key);
@endphp
<x-layouts.app title="Draft report" :subtitle="$assessment->movement->name.' · '.$assessment->period_label.'. Drafted from the OHA form: read each section, correct it, and add what is missing.'">
    <x-button variant="link" icon="arrow_back" :href="route('assessments.show', ['assessment' => $assessment, 'tab' => 'report'])" class="text-[0.875rem] self-start">Back to the Report tab</x-button>

    <x-card title="About this draft">
        <div class="flex flex-col gap-sm text-[0.875rem]">
            <p>
                Drafted by {{ $draft->creator->name }} on {{ $draft->created_at->asDay() }} from the OHA form
                {{ $draft->formUpload ? '“'.$draft->formUpload->original_name.'”' : '' }}.
                Last saved by <strong>{{ $draft->updater->name }}</strong>, {{ $draft->updated_at->diffForHumans() }}.
            </p>
            <div class="flex flex-wrap items-center gap-sm">
                @if ($toComplete > 0)
                    <x-chip tone="warning" icon="edit_note">{{ $toComplete }} “To complete” left</x-chip>
                @else
                    <x-chip tone="good" icon="task_alt">Nothing left to complete</x-chip>
                @endif
                <x-chip tone="info" icon="lock">The score table follows the OHA form</x-chip>
            </div>
            @if ($formChanged)
                <x-callout tone="warning" icon="sync_problem">
                    <p><strong>The OHA form has changed since this draft was made.</strong> Update the facts to bring the scores and figures in line with it; your corrections to the text are kept.</p>
                    <form method="POST" action="{{ route('report-draft.facts', $artefact) }}">
                        @csrf
                        <x-button>Update the facts</x-button>
                    </form>
                </x-callout>
            @endif
            <p class="text-on-surface-variant">
                Two people may work on the draft at once, on different sections. Each section shows who saved it last; if someone saved it after you opened this page, your save stops so their words are not lost.
            </p>
            <div class="flex flex-wrap items-center gap-md pt-xs">
                <x-button variant="link" icon="description" href="#make" class="font-semibold">Create the Word report</x-button>
                <x-confirm-form :action="route('report-draft.start', $artefact)" confirm="Write the whole draft again from the OHA form? Every correction made so far will be lost.">
                    <input type="hidden" name="again" value="1">
                    <x-button variant="link" icon="restart_alt">Start again from the form</x-button>
                </x-confirm-form>
                <x-confirm-form :action="route('report-draft.destroy', $artefact)" method="DELETE" confirm="Discard this draft? It cannot be undone. Uploaded report versions are not affected.">
                    <x-button variant="link-danger" icon="delete">Discard the draft</x-button>
                </x-confirm-form>
            </div>
        </div>
    </x-card>

    @foreach ($draft->sections as $section)
        @php
            $key = $section['key'];
            $open = substr_count((string) json_encode($section, JSON_UNESCAPED_UNICODE), '[To complete');
            $sources = array_values(array_filter($section['sources'] ?? [], fn ($c) => isset($labels[$c]) || array_key_exists($c, $answers)));
        @endphp

        @if ($section['kind'] === 'heading')
            <h2 id="{{ $anchor($key) }}" class="pt-md text-[1.125rem] font-bold text-primary">{{ $section['title'] }}</h2>
            @continue
        @endif

        <section id="{{ $anchor($key) }}" class="scroll-mt-24">
            <x-card :title="$section['kind'] === 'title' ? 'Title' : $section['title']"
                    :subtitle="isset($section['saved_at']) ? 'Saved by '.$section['saved_by_name'].', '.\Illuminate\Support\Carbon::parse($section['saved_at'])->diffForHumans().'.' : 'As drafted from the form; not changed yet.'">
                <x-slot:actions>
                    @if ($open > 0)
                        <x-chip tone="warning" icon="edit_note">{{ $open }} to complete</x-chip>
                    @endif
                    @if ($section['kind'] === 'custom')
                        <x-chip tone="info" icon="add_notes">Added by the assessors</x-chip>
                    @endif
                </x-slot:actions>

                @if ($section['kind'] === 'scores')
                    <div class="overflow-x-auto">
                        <table class="w-full text-[0.875rem]">
                            <thead>
                                <tr class="text-left text-on-surface-variant border-b border-outline-variant">
                                    <th class="py-xs pr-sm font-semibold">Category</th><th class="py-xs pr-sm font-semibold">Score</th><th class="py-xs pr-sm font-semibold">Maximum</th><th class="py-xs pr-sm font-semibold">Status</th><th class="py-xs font-semibold">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['rows'] as $r)
                                    <tr class="border-b border-surface-container">
                                        <td class="py-xs pr-sm">{{ $r['name'] }}</td>
                                        <td class="py-xs pr-sm tabular-nums">{{ \App\Support\Viz::figure((float) $r['points']) }}</td>
                                        <td class="py-xs pr-sm tabular-nums">{{ $r['max'] }}</td>
                                        <td class="py-xs pr-sm"><span class="inline-block size-2.5 rounded-full mr-xs align-middle" style="background-color: {{ $r['color'] }}"></span>{{ $r['band_label'] }}</td>
                                        <td class="py-xs tabular-nums">{{ number_format((float) $r['pct'], 2) }}%</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td class="py-xs pr-sm">Overall</td>
                                    <td class="py-xs pr-sm tabular-nums">{{ \App\Support\Viz::figure((float) $section['overall']['points']) }}</td>
                                    <td class="py-xs pr-sm tabular-nums">{{ $section['overall']['max'] }}</td>
                                    <td class="py-xs pr-sm">{{ $section['overall']['band_label'] }}</td>
                                    <td class="py-xs tabular-nums">{{ number_format((float) $section['overall']['pct'], 2) }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="flex items-center gap-xs text-[0.8125rem] text-on-surface-variant">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">lock</span>
                        From the OHA form, so the report always agrees with it. It changes only when the form does.
                    </p>
                @else
                    <form method="POST" action="{{ route('report-draft.save', ['artefact' => $artefact, 'key' => $key]) }}" class="flex flex-col gap-sm">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="seen" value="{{ $section['saved_at'] ?? '' }}">

                        @switch($section['kind'])
                            @case('title')
                                @foreach (['Report title', 'Movement and month', 'Report by'] as $i => $label)
                                    <x-field :label="$label">
                                        <input type="text" name="lines[{{ $i }}]" value="{{ $section['lines'][$i] ?? '' }}" maxlength="300" @class($box)>
                                    </x-field>
                                @endforeach
                                @break
                            @case('list')
                                <x-field label="One point per line">
                                    @php($value = implode("\n", $section['items']))
                                    <textarea name="items" rows="{{ $rows($value) + count($section['items']) }}" @class($box)>{{ $value }}</textarea>
                                </x-field>
                                @break
                            @case('category')
                                <x-field label="Analysis">
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" @class($box)>{{ $section['text'] }}</textarea>
                                </x-field>
                                <x-field label="Opportunities for Growth: one per line">
                                    @php($value = implode("\n", $section['items']))
                                    <textarea name="items" rows="{{ max(2, count($section['items']) + 1) }}" @class($box)>{{ $value }}</textarea>
                                </x-field>
                                @break
                            @case('custom')
                                <x-field label="Section title">
                                    <input type="text" name="title" value="{{ $section['title'] }}" maxlength="200" @class($box)>
                                </x-field>
                                <x-field label="Text">
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" @class($box) placeholder="Write this section. Leave an empty line between paragraphs.">{{ $section['text'] }}</textarea>
                                </x-field>
                                @break
                            @default
                                <label @class(\App\Support\Ui::field())>
                                    <span class="font-semibold text-on-surface">Text <span class="font-normal text-on-surface-variant">(an empty line between paragraphs)</span></span>
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" @class($box)>{{ $section['text'] }}</textarea>
                                </label>
                        @endswitch

                        <div class="flex flex-wrap items-center gap-md">
                            <x-button icon="save">Save</x-button>
                        </div>
                    </form>

                    <div class="flex flex-wrap items-center gap-md text-[0.875rem]">
                        @if ($section['kind'] !== 'custom')
                            <x-confirm-form :action="route('report-draft.rewrite', ['artefact' => $artefact, 'key' => $key])"
                                            :confirm="'Write “'.($section['kind'] === 'title' ? 'Title' : $section['title']).'” again from the OHA form? The corrections made to it will be lost.'">
                                <x-button variant="link" icon="autorenew">Rewrite this section from the form</x-button>
                            </x-confirm-form>
                        @else
                            <x-confirm-form :action="route('report-draft.remove', ['artefact' => $artefact, 'key' => $key])" method="DELETE"
                                            :confirm="'Remove the section “'.$section['title'].'”?'">
                                <x-button variant="link-danger" icon="delete">Remove this section</x-button>
                            </x-confirm-form>
                        @endif
                    </div>
                @endif

                @if ($sources !== [])
                    <details class="text-[0.8125rem]">
                        <summary class="cursor-pointer text-primary font-semibold">The form’s answers behind this section ({{ count($sources) }})</summary>
                        <dl class="mt-sm grid sm:grid-cols-[6rem_1fr_9rem] gap-x-md gap-y-xs">
                            @foreach ($sources as $code)
                                @php($answer = $answers[$code] ?? null)
                                <dt class="font-semibold tabular-nums">{{ str_replace('#2', ' (2nd)', $code) }}</dt>
                                <dd class="text-on-surface-variant">{{ \Illuminate\Support\Str::limit($labels[$code] ?? '', 140) }}</dd>
                                <dd class="{{ $answer === null || $answer === '' ? 'text-warning-ink' : '' }}">{{ $answer === null || $answer === '' ? 'Not answered' : \Illuminate\Support\Str::limit(is_scalar($answer) ? (string) $answer : json_encode($answer), 60) }}</dd>
                            @endforeach
                        </dl>
                    </details>
                @endif
            </x-card>
        </section>
    @endforeach

    <x-card title="Add a section" subtitle="For anything the form does not cover: Programmes and Projects, Recommendations, a note on the assessment visit…">
        <form method="POST" action="{{ route('report-draft.add', $artefact) }}" class="flex flex-col sm:flex-row sm:items-end gap-sm">
            @csrf
            <x-input name="title" label="Title" required maxlength="200" placeholder="e.g. Programmes and Projects" wrap="flex-1" />
            <x-select name="after" label="Place it after" :options="$places" :value="array_key_last($places)" wrap="sm:w-72" />
            <x-button>Add</x-button>
        </form>
    </x-card>

    {{-- The draft becomes the report: the official template, saved as the report's next version. --}}
    <section id="make" class="scroll-mt-24">
        <x-card title="Create the Word report" subtitle="The draft is written into the official AAYMCA report template and saved as the report’s next version. From there it goes for approval like any report, and it can still be downloaded, edited in Word and uploaded again.">
            <form method="POST" action="{{ route('report-draft.make', $artefact) }}" class="flex flex-col gap-sm" x-data="{ read: false }">
                @csrf
                @if ($toComplete > 0)
                    <x-callout tone="warning" icon="edit_note">
                        <p>{{ $toComplete }} “To complete” {{ $toComplete === 1 ? 'is' : 'are' }} still in the draft. {{ $toComplete === 1 ? 'It' : 'They' }} would appear in the report as {{ $toComplete === 1 ? 'it is' : 'they are' }}, and the report check would flag {{ $toComplete === 1 ? 'it' : 'them' }}.</p>
                    </x-callout>
                @endif
                @include('assessments.tabs._replace-guard')
                @if (\App\Support\ReplaceGuard::current($artefact))
                    <x-textarea name="note" label="What changed in this version? (optional)" rows="2" maxlength="2000" />
                @endif
                <label class="flex items-start gap-sm text-[0.875rem] font-semibold">
                    <input type="hidden" name="read_every_section" value="0">
                    <input type="checkbox" name="read_every_section" value="1" class="mt-1" x-model="read">
                    I have read and checked every section of this draft.
                </label>
                <x-button class="self-start" icon="description" x-bind:disabled="! read">Create the Word report</x-button>
            </form>
        </x-card>
    </section>
</x-layouts.app>
