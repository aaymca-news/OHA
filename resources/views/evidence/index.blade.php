{{--
    A movement's evidence library, one assessment at a time. Ten folders (the nine OHA
    categories and General); each holds what its category's "Yes" answers need, flagged
    "Missing" until supplied, and the evidence filed there. Adding evidence: a file or a
    link, filed under a category, linked to the form's questions and the ODP's tasks.
    Expects $movement, $assessments, $assessment, $documents, $categories, $questions,
    $checklist, $for, $canUpload and $canShareNew.
--}}
@use('App\Models\Evidence')
@use('App\Oha\EvidenceQuestions')
@php($categoryNames = $categories->pluck('name', 'code')->all())
@php($forCategory = $for !== null ? EvidenceQuestions::categoryOf($for) : null)
<x-layouts.app :title="'Evidence: '.$movement->name"
               subtitle="What each “Yes” on the OHA form needs as evidence, what has been supplied, and the evidence behind the ODP’s tasks.">

    <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
        <a href="{{ route('resources') }}" class="inline-flex items-center gap-xs text-primary underline">
            <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">arrow_back</span> Resources
        </a>
        @if ($assessments->count() > 1)
            <nav class="ml-auto flex flex-wrap rounded border-[1.5px] border-outline-variant overflow-hidden text-[0.8125rem]" aria-label="Which assessment">
                @foreach ($assessments as $a)
                    <a href="{{ route('evidence.index', ['movement' => $movement, 'assessment' => $a->id]) }}" @if ($a->id === $assessment->id) aria-current="page" @endif
                       @class(['px-sm py-1.5', 'bg-primary text-on-primary' => $a->id === $assessment->id, 'hover:bg-surface-container' => $a->id !== $assessment->id])>{{ $a->period_label }}</a>
                @endforeach
            </nav>
        @endif
    </div>

    @if ($assessment === null)
        <x-empty-state icon="folder_open">{{ $movement->name }} has no assessment yet. Evidence is added for an assessment once it is started.</x-empty-state>
    @else
        @php($needed = collect($checklist)->flatten(1))
        @php($backed = $needed->filter(fn ($q) => $q['documents'] !== [])->count())
        <div class="grid sm:grid-cols-3 gap-md">
            <x-stat-tile label="Assessment" :value="$assessment->period_label">
                <x-slot:sub>Each assessment gathers its own evidence.</x-slot:sub>
            </x-stat-tile>
            <x-stat-tile label="“Yes” answers with evidence" :value="$needed->isEmpty() ? '—' : $backed.' / '.$needed->count()">
                <x-slot:sub>{{ $needed->isEmpty() ? 'Shown once the OHA form is '.(auth()->user()->isSecretariat() ? 'uploaded' : 'approved').'.' : ($needed->count() - $backed).' still missing' }}</x-slot:sub>
            </x-stat-tile>
            <x-stat-tile label="Evidence in the library" :value="(string) $documents->count()">
                <x-slot:sub>{{ $documents->filter(fn ($r) => $r['evidence']->isLink())->count() }} links · {{ $documents->reject(fn ($r) => $r['evidence']->isLink())->count() }} files</x-slot:sub>
            </x-stat-tile>
        </div>

        @if ($canUpload)
            <details id="add" class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg" @if ($errors->any() || $for) open @endif>
                <summary class="cursor-pointer px-md py-sm font-semibold text-primary inline-flex items-center gap-xs">
                    <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true">upload_file</span> Add evidence
                </summary>
                <form method="POST" action="{{ route('evidence.store', $movement) }}" enctype="multipart/form-data"
                      x-data="{ category: @js(old('category', $forCategory ?? '')), kind: @js(old('kind', $for !== null && EvidenceQuestions::accepts($for) === EvidenceQuestions::LINK ? 'link' : 'file')) }"
                      class="flex flex-col gap-md px-md pb-md">
                    @csrf
                    <input type="hidden" name="assessment_id" value="{{ $assessment->id }}">

                    @if ($for)
                        <p class="text-[0.875rem] p-sm rounded bg-info-wash text-info-ink">
                            For <strong>{{ EvidenceQuestions::shown($for) }} {{ EvidenceQuestions::label($for) }}</strong>. Needed: {{ EvidenceQuestions::needed($for) }}
                        </p>
                    @endif

                    <fieldset class="flex flex-wrap gap-md text-[0.875rem]">
                        <legend class="font-semibold mb-xs">The evidence is…</legend>
                        <label class="flex items-center gap-xs"><input type="radio" name="kind" value="file" x-model="kind"> A file</label>
                        <label class="flex items-center gap-xs"><input type="radio" name="kind" value="link" x-model="kind"> A link (for example the website)</label>
                    </fieldset>

                    <div class="grid sm:grid-cols-2 gap-md">
                        <label x-show="kind === 'file'" class="flex flex-col gap-xs text-[0.875rem]">
                            <span class="font-semibold">File</span>
                            <input type="file" name="file" :required="kind === 'file'" :disabled="kind !== 'file'" accept="{{ '.'.implode(',.', Evidence::FORMATS) }}"
                                   class="text-[0.875rem] file:mr-sm file:px-md file:py-1.5 file:rounded file:border-[1.5px] file:border-outline-variant file:bg-surface-container-lowest file:text-primary file:font-semibold hover:file:border-primary">
                            <span class="text-[0.8125rem] text-on-surface-variant">PDF, Word, Excel, PowerPoint, images or text, up to {{ (int) config('oha.max_upload_kb') / 1024 }} MB.</span>
                        </label>
                        <label x-show="kind === 'link'" x-cloak class="flex flex-col gap-xs text-[0.875rem]">
                            <span class="font-semibold">Link</span>
                            <input type="url" name="url" value="{{ old('url') }}" placeholder="https://" :required="kind === 'link'" :disabled="kind !== 'link'"
                                   class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                            <span class="text-[0.8125rem] text-on-surface-variant">The full address, starting with https://.</span>
                        </label>
                        <x-input name="title" label="Title" :value="old('title')" hint="For example: Safeguarding Policy 2024. Left empty, the file’s name or the website is used." />
                    </div>

                    <label class="flex flex-col gap-xs text-[0.875rem] sm:max-w-[24rem]">
                        <span class="font-semibold">File it under</span>
                        <select name="category" x-model="category" class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest">
                            <option value="">General (not one category)</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->code }}">{{ $category->form_order }}. {{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <fieldset class="flex flex-col gap-xs text-[0.875rem]">
                        <legend class="font-semibold mb-xs">The form’s questions it proves <span class="font-normal text-on-surface-variant">(optional; a website takes a link, a policy a document)</span></legend>
                        @foreach ($questions as $code => $list)
                            <div x-show="category === '' || category === @js($code)" class="flex flex-col gap-xs">
                                <p x-show="category === ''" class="text-[0.8125rem] font-semibold text-on-surface-variant mt-xs">{{ $categoryNames[$code] ?? $code }}</p>
                                <div class="grid sm:grid-cols-2 gap-x-md gap-y-xs">
                                    @foreach ($list as $q => $label)
                                        @php($accepts = EvidenceQuestions::accepts($q))
                                        {{-- A question that takes only a link is offered with a link, one that takes only a document with a file. --}}
                                        <label class="flex items-start gap-sm"
                                               @if ($accepts === EvidenceQuestions::LINK) x-show="kind === 'link'" @elseif ($accepts === EvidenceQuestions::DOCUMENT) x-show="kind === 'file'" @endif>
                                            <input type="checkbox" name="questions[]" value="{{ $q }}" class="mt-1" @checked(in_array($q, old('questions', $for ? [$for] : []), true))>
                                            <span><span class="text-on-surface-variant">{{ EvidenceQuestions::shown($q) }}</span> {{ $label }}{{ $accepts === EvidenceQuestions::LINK ? ' (link)' : '' }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </fieldset>

                    <x-input name="tasks" label="The ODP tasks it supports (optional)" :value="old('tasks')"
                             hint="Task numbers as on the ODP, for example 5.1, 5.2. The first number is the priority: 1 Financial Stability … 9 Staff & Volunteer Development." />

                    @if ($canShareNew)
                        <label class="flex items-start gap-sm text-[0.875rem]">
                            <input type="hidden" name="share" value="0">
                            <input type="checkbox" name="share" value="1" class="mt-1" @checked(old('share'))>
                            <span>Share this with the other movements. They see this evidence only, never {{ $movement->name }}’s scores or form.</span>
                        </label>
                    @endif

                    <x-button class="self-start">Add evidence</x-button>
                </form>
            </details>
        @elseif (auth()->user()->isGeneralSecretaryOf($movement))
            <p class="flex items-start gap-sm p-md rounded-lg bg-info-wash text-info-ink text-[0.875rem]">
                <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                You can add your supporting documents here once AAYMCA has approved this assessment’s ODP. You will be told when it is.
            </p>
        @endif

        {{-- Ten folders: in each, what its "Yes" answers need (missing flagged here), then the evidence filed in it. --}}
        @php($folders = $categories->map(fn ($c) => ['code' => $c->code, 'name' => $c->form_order.'. '.$c->name, 'id' => $c->id])->push(['code' => 'general', 'name' => 'General', 'id' => null]))
        <div class="flex flex-col gap-sm">
            @foreach ($folders as $folder)
                @php($rows = $documents->filter(fn ($r) => $r['evidence']->category_id === $folder['id']))
                @php($asks = $checklist[$folder['code']] ?? [])
                @php($missing = collect($asks)->where('documents', [])->count())
                <details class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg text-[0.875rem]" @if ($forCategory === $folder['code']) open @endif>
                    <summary class="cursor-pointer px-md py-sm flex flex-wrap items-center gap-sm">
                        <span class="material-symbols-outlined text-[1.25rem] text-primary" aria-hidden="true">{{ $rows->isEmpty() ? 'folder' : 'folder_open' }}</span>
                        <span class="font-semibold flex-1 min-w-[min(10rem,100%)]">{{ $folder['name'] }}</span>
                        @if ($missing > 0)
                            <x-chip tone="warning" icon="pending">{{ $missing }} missing</x-chip>
                        @elseif ($asks !== [])
                            <x-chip tone="good" icon="task_alt">All supplied</x-chip>
                        @endif
                        <x-chip tone="neutral">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('item', $rows->count()) }}</x-chip>
                    </summary>

                    <div class="flex flex-col gap-md px-md pb-md">
                        @if ($asks !== [])
                            <div class="flex flex-col gap-xs">
                                <p class="text-[0.8125rem] font-semibold uppercase tracking-wider text-on-surface-variant">Needed for the form’s “Yes” answers</p>
                                <ul class="flex flex-col gap-xs">
                                    @foreach ($asks as $q)
                                        @include('evidence._requirement', [
                                            'q' => $q,
                                            'addLink' => $canUpload ? route('evidence.index', ['movement' => $movement, 'assessment' => $assessment->id, 'for' => $q['code']]).'#add' : null,
                                        ])
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="flex flex-col gap-xs">
                            <p class="text-[0.8125rem] font-semibold uppercase tracking-wider text-on-surface-variant">Evidence filed here</p>
                            @forelse ($rows as $row)
                                @php($doc = $row['evidence'])
                                <div class="flex flex-col gap-xs p-sm rounded bg-surface-container-low">
                                    <div class="flex flex-wrap items-start gap-sm">
                                        <x-evidence-link :doc="$doc" class="font-semibold flex-1 min-w-[min(12rem,100%)]" />
                                        @if ($doc->isShared())
                                            <x-chip tone="info" icon="share">Shared with other movements</x-chip>
                                        @endif
                                    </div>
                                    <p class="text-[0.8125rem] text-on-surface-variant break-words">
                                        {{ $doc->describe() }} · added by {{ $doc->uploader->name }}, {{ $doc->uploaded_at->format('j M Y') }}
                                    </p>
                                    @if ($row['questions'] !== [] || $row['tasks'] !== [])
                                        <p class="flex flex-wrap gap-xs">
                                            @foreach ($row['questions'] as $q)
                                                <x-chip tone="neutral" icon="table_view">{{ EvidenceQuestions::shown($q) }} {{ EvidenceQuestions::label($q) }}</x-chip>
                                            @endforeach
                                            @foreach ($row['tasks'] as $task)
                                                <x-chip tone="neutral" icon="checklist">ODP {{ $task }}</x-chip>
                                            @endforeach
                                        </p>
                                    @endif
                                    @canany(['share', 'move', 'remove'], $doc)
                                        <div class="flex flex-wrap items-center gap-sm">
                                            @can('move', $doc)
                                                {{-- Filed in the wrong folder: move it. Links that belong to another category come off. --}}
                                                <form method="POST" action="{{ route('evidence.move', $doc) }}" class="flex flex-wrap items-center gap-xs">
                                                    @csrf
                                                    @method('PUT')
                                                    <label class="sr-only" for="move-{{ $doc->id }}">Move “{{ $doc->title }}” to</label>
                                                    <select id="move-{{ $doc->id }}" name="category" required class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.8125rem]">
                                                        <option value="" selected disabled>Move to another folder…</option>
                                                        @foreach ($folders as $target)
                                                            @continue($target['id'] === $doc->category_id)
                                                            <option value="{{ $target['code'] }}">{{ $target['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-button variant="secondary">Move</x-button>
                                                </form>
                                            @endcan
                                            @can('share', $doc)
                                                <form method="POST" action="{{ route('evidence.share', $doc) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="shared" value="{{ $doc->isShared() ? 0 : 1 }}">
                                                    <x-button variant="secondary">{{ $doc->isShared() ? 'Stop sharing' : 'Share with other movements' }}</x-button>
                                                </form>
                                            @endcan
                                            @can('remove', $doc)
                                                <form method="POST" action="{{ route('evidence.destroy', $doc) }}" x-data
                                                      @submit="if (! confirm('Remove “{{ addslashes($doc->title) }}” from the evidence?')) $event.preventDefault()">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-button variant="danger">Remove</x-button>
                                                </form>
                                            @endcan
                                        </div>
                                    @endcanany
                                </div>
                            @empty
                                <p class="text-on-surface-variant">Nothing filed here yet.</p>
                            @endforelse
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</x-layouts.app>
