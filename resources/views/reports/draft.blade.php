{{--
    The report drafted from the OHA form, corrected section by section. Expects $artefact
    (the report), $assessment, $draft, $formChanged, and the form's $labels and $answers
    (shown beside each section, so the reviewer sees why it says what it says).
--}}
@php
    $field = 'w-full px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.9375rem] leading-relaxed';
    $rows = fn (string $text) => max(3, min(18, (int) ceil(mb_strlen($text) / 95) + substr_count($text, "\n") + 1));
    $toComplete = $draft->toComplete();
    $anchor = fn (string $key) => str_replace(':', '-', $key);
@endphp
<x-layouts.app title="Draft report" :subtitle="$assessment->movement->name.' · '.$assessment->period_label.'. Drafted from the OHA form: read each section, correct it, and add what is missing.'">
    <a href="{{ route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']) }}" class="inline-flex items-center gap-xs text-[0.875rem] text-primary underline self-start">
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">arrow_back</span> Back to the Report tab
    </a>

    <x-card title="About this draft">
        <div class="flex flex-col gap-sm text-[0.875rem]">
            <p>
                Drafted by {{ $draft->creator->name }} on {{ $draft->created_at->format('j M Y') }} from the OHA form
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
                <div class="flex flex-wrap items-center gap-sm p-md rounded-lg bg-warning-wash text-warning-ink">
                    <span class="material-symbols-outlined" aria-hidden="true">sync_problem</span>
                    <span class="flex-1 min-w-[min(16rem,100%)]"><strong>The OHA form has changed since this draft was made.</strong> Update the facts to bring the scores and figures in line with it; your corrections to the text are kept.</span>
                    <form method="POST" action="{{ route('report-draft.facts', $artefact) }}">
                        @csrf
                        <x-button>Update the facts</x-button>
                    </form>
                </div>
            @endif
            <p class="text-on-surface-variant">
                Two people may work on the draft at once, on different sections. Each section shows who saved it last; if someone saved it after you opened this page, your save stops so their words are not lost.
            </p>
            <div class="flex flex-wrap items-center gap-md pt-xs">
                <a href="#make" class="inline-flex items-center gap-xs text-primary underline font-semibold">
                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">description</span> Create the Word report
                </a>
                <form method="POST" action="{{ route('report-draft.start', $artefact) }}"
                      x-data x-on:submit="if (! confirm('Write the whole draft again from the OHA form? Every correction made so far will be lost.')) $event.preventDefault()">
                    @csrf
                    <input type="hidden" name="again" value="1">
                    <button class="inline-flex items-center gap-xs text-primary underline">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">restart_alt</span> Start again from the form
                    </button>
                </form>
                <form method="POST" action="{{ route('report-draft.destroy', $artefact) }}"
                      x-data x-on:submit="if (! confirm('Discard this draft? It cannot be undone. Uploaded report versions are not affected.')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button class="inline-flex items-center gap-xs text-error underline">
                        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">delete</span> Discard the draft
                    </button>
                </form>
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
                                        <td class="py-xs pr-sm tabular-nums">{{ rtrim(rtrim(number_format((float) $r['points'], 2), '0'), '.') }}</td>
                                        <td class="py-xs pr-sm tabular-nums">{{ $r['max'] }}</td>
                                        <td class="py-xs pr-sm"><span class="inline-block size-2.5 rounded-full mr-xs align-middle" style="background-color: {{ $r['color'] }}"></span>{{ $r['band_label'] }}</td>
                                        <td class="py-xs tabular-nums">{{ number_format((float) $r['pct'], 2) }}%</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td class="py-xs pr-sm">Overall</td>
                                    <td class="py-xs pr-sm tabular-nums">{{ rtrim(rtrim(number_format((float) $section['overall']['points'], 2), '0'), '.') }}</td>
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
                                    <label class="flex flex-col gap-xs text-[0.875rem]">
                                        <span class="font-semibold">{{ $label }}</span>
                                        <input type="text" name="lines[{{ $i }}]" value="{{ $section['lines'][$i] ?? '' }}" maxlength="300" class="{{ $field }}">
                                    </label>
                                @endforeach
                                @break
                            @case('list')
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">One point per line</span>
                                    @php($value = implode("\n", $section['items']))
                                    <textarea name="items" rows="{{ $rows($value) + count($section['items']) }}" class="{{ $field }}">{{ $value }}</textarea>
                                </label>
                                @break
                            @case('category')
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">Analysis</span>
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" class="{{ $field }}">{{ $section['text'] }}</textarea>
                                </label>
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">Opportunities for Growth: one per line</span>
                                    @php($value = implode("\n", $section['items']))
                                    <textarea name="items" rows="{{ max(2, count($section['items']) + 1) }}" class="{{ $field }}">{{ $value }}</textarea>
                                </label>
                                @break
                            @case('custom')
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">Section title</span>
                                    <input type="text" name="title" value="{{ $section['title'] }}" maxlength="200" class="{{ $field }}">
                                </label>
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">Text</span>
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" class="{{ $field }}" placeholder="Write this section. Leave an empty line between paragraphs.">{{ $section['text'] }}</textarea>
                                </label>
                                @break
                            @default
                                <label class="flex flex-col gap-xs text-[0.875rem]">
                                    <span class="font-semibold">Text <span class="font-normal text-on-surface-variant">(an empty line between paragraphs)</span></span>
                                    <textarea name="text" rows="{{ $rows($section['text']) }}" class="{{ $field }}">{{ $section['text'] }}</textarea>
                                </label>
                        @endswitch

                        <div class="flex flex-wrap items-center gap-md">
                            <x-button>
                                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">save</span> Save</span>
                            </x-button>
                        </div>
                    </form>

                    <div class="flex flex-wrap items-center gap-md text-[0.875rem]">
                        @if ($section['kind'] !== 'custom')
                            <form method="POST" action="{{ route('report-draft.rewrite', ['artefact' => $artefact, 'key' => $key]) }}"
                                  x-data x-on:submit="if (! confirm(@js('Write “'.($section['kind'] === 'title' ? 'Title' : $section['title']).'” again from the OHA form? The corrections made to it will be lost.'))) $event.preventDefault()">
                                @csrf
                                <button class="inline-flex items-center gap-xs text-primary underline">
                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">autorenew</span> Rewrite this section from the form
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('report-draft.remove', ['artefact' => $artefact, 'key' => $key]) }}"
                                  x-data x-on:submit="if (! confirm(@js('Remove the section “'.$section['title'].'”?'))) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex items-center gap-xs text-error underline">
                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">delete</span> Remove this section
                                </button>
                            </form>
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
            <label class="flex flex-col gap-xs text-[0.875rem] flex-1">
                <span class="font-semibold">Title</span>
                <input type="text" name="title" required maxlength="200" class="{{ $field }}" placeholder="e.g. Programmes and Projects">
            </label>
            <label class="flex flex-col gap-xs text-[0.875rem] sm:w-72">
                <span class="font-semibold">Place it after</span>
                <select name="after" class="{{ $field }}">
                    @foreach ($draft->sections as $s)
                        @continue($s['kind'] === 'heading')
                        <option value="{{ $s['key'] }}" @selected($loop->last)>{{ $s['kind'] === 'title' ? 'Title' : $s['title'] }}</option>
                    @endforeach
                </select>
            </label>
            <x-button>Add</x-button>
        </form>
    </x-card>

    {{-- The draft becomes the report: the official template, saved as the report's next version. --}}
    <section id="make" class="scroll-mt-24">
        <x-card title="Create the Word report" subtitle="The draft is written into the official AAYMCA report template and saved as the report’s next version. From there it goes for approval like any report, and it can still be downloaded, edited in Word and uploaded again.">
            <form method="POST" action="{{ route('report-draft.make', $artefact) }}" class="flex flex-col gap-sm" x-data="{ read: false }">
                @csrf
                @if ($toComplete > 0)
                    <p class="flex items-start gap-sm p-md rounded-lg bg-warning-wash text-warning-ink text-[0.875rem]">
                        <span class="material-symbols-outlined" aria-hidden="true">edit_note</span>
                        <span>{{ $toComplete }} “To complete” {{ $toComplete === 1 ? 'is' : 'are' }} still in the draft. {{ $toComplete === 1 ? 'It' : 'They' }} would appear in the report as {{ $toComplete === 1 ? 'it is' : 'they are' }}, and the report check would flag {{ $toComplete === 1 ? 'it' : 'them' }}.</span>
                    </p>
                @endif
                @include('assessments.tabs._replace-guard')
                @if (\App\Support\ReplaceGuard::current($artefact))
                    <label class="flex flex-col gap-xs text-[0.875rem]">
                        <span class="font-semibold">What changed in this version? (optional)</span>
                        <textarea name="note" rows="2" maxlength="2000" class="{{ $field }}">{{ old('note') }}</textarea>
                    </label>
                @endif
                <label class="flex items-start gap-sm text-[0.875rem] font-semibold">
                    <input type="hidden" name="read_every_section" value="0">
                    <input type="checkbox" name="read_every_section" value="1" class="mt-1" x-model="read">
                    I have read and checked every section of this draft.
                </label>
                <x-button class="self-start disabled:opacity-50 disabled:cursor-not-allowed" x-bind:disabled="! read">
                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">description</span> Create the Word report</span>
                </x-button>
            </form>
        </x-card>
    </section>
</x-layouts.app>
