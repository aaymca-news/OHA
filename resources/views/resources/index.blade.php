{{--
    Resources: the approved OHA form, report and ODP of each movement, one card per movement.
    Board Chairpersons see their own movement only. Expects $movements, $assessments
    (movement id => list of [assessment, form, report, odp, signature]), $counts and $filters.
--}}
@php($secretariat = auth()->user()->isSecretariat())
<x-layouts.app :title="__('oha.nav.resources')"
               :subtitle="$secretariat
                    ? 'What the Administrators have approved, movement by movement: the OHA form, the report and the ODP, to read or download.'
                    : 'Your movement’s approved OHA form, report and ODP, to read or download.'">

    @if ($secretariat)
        <form method="GET" class="flex flex-wrap items-end gap-sm" aria-label="Find a movement">
            <label class="flex flex-col gap-xs text-[0.8125rem] grow sm:grow-0">
                <span class="font-semibold">Search</span>
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Movement or country"
                       class="px-sm py-1.5 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-[0.875rem] sm:w-56">
            </label>
            @if ($filters['show'])
                <input type="hidden" name="show" value="all">
            @endif
            <x-button variant="secondary">Search</x-button>
            @if ($filters['q'] !== '')
                <a href="{{ route('resources', array_filter(['show' => $filters['show']])) }}" class="text-[0.875rem] text-primary underline pb-2">Clear</a>
            @endif
            <nav class="ml-auto flex rounded border-[1.5px] border-outline-variant overflow-hidden text-[0.8125rem]" aria-label="Which movements">
                @foreach ([null => 'With documents ('.$counts['with'].')', 'all' => 'All movements'] as $key => $label)
                    <a href="{{ route('resources', array_filter(['q' => $filters['q'], 'show' => $key])) }}" @if ($filters['show'] === ($key ?: null)) aria-current="page" @endif
                       @class(['px-sm py-1.5', 'bg-primary text-on-primary' => $filters['show'] === ($key ?: null), 'hover:bg-surface-container' => $filters['show'] !== ($key ?: null)])>{{ $label }}</a>
                @endforeach
            </nav>
        </form>
    @endif

    @if ($movements->isEmpty())
        <x-empty-state icon="folder_open">
            @if ($filters['q'] !== '')
                No movement matches “{{ $filters['q'] }}”.
            @elseif ($secretariat)
                Nothing has been approved yet. Each movement’s OHA form, report and ODP appear here once an Administrator approves them.
            @else
                Nothing has been approved yet.
            @endif
        </x-empty-state>
    @else
        <div class="grid gap-md md:grid-cols-2 items-start">
            @foreach ($movements as $movement)
                @php($list = $assessments->get($movement->id, collect()))
                <section class="bg-surface-container-lowest border-[1.5px] border-outline-variant rounded-lg p-md flex flex-col gap-sm" aria-labelledby="res-{{ $movement->id }}">
                    <header class="flex flex-wrap items-start gap-sm">
                        <div class="flex-1 min-w-40">
                            <h2 id="res-{{ $movement->id }}" class="text-[1rem] font-bold text-primary">
                                <a href="{{ route('movements.show', $movement) }}" class="underline decoration-1 underline-offset-2">{{ $movement->name }}</a>
                            </h2>
                            <p class="text-[0.8125rem] text-on-surface-variant">{{ $movement->city }}, {{ $movement->country }} · {{ $movement->zone->name }}</p>
                        </div>
                        @if ($list->first()['assessment']->score ?? null)
                            <x-band-chip :band="$list->first()['assessment']->score->band" />
                        @endif
                    </header>

                    @forelse ($list as $i => $r)
                        @php($a = $r['assessment'])
                        @if ($i === 1)
                            <details class="text-[0.875rem]">
                                <summary class="cursor-pointer text-primary font-semibold py-1">Earlier assessments ({{ $list->count() - 1 }})</summary>
                                <div class="flex flex-col gap-sm mt-sm">
                        @endif
                        <div class="flex flex-col gap-xs">
                            <p class="text-[0.8125rem] font-semibold text-on-surface-variant">
                                <a href="{{ route('assessments.show', $a) }}" class="underline">{{ $a->period_label }}</a>
                                @if ($a->score)
                                    · {{ rtrim(rtrim(number_format((float) $a->score->points_achieved, 1), '0'), '.') }} / {{ $a->score->points_available }} points
                                @endif
                            </p>
                            <ul class="flex flex-col divide-y divide-surface-container rounded border-[1.5px] border-outline-variant">
                                @foreach ([
                                    ['form', 'table_view', 'OHA form', $r['form'], $r['form'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'form', 'upload' => $r['form']->id]).'#form-preview' : null, $r['form'] ? route('downloads.form', $r['form']) : null],
                                    ['report', 'description', 'Report', $r['report'], $r['report'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'report', 'version' => $r['report']->id]) : null, $r['report'] ? route('downloads.document', $r['report']) : null],
                                    ['odp', 'checklist', 'ODP', $r['odp'], $r['odp'] ? route('assessments.show', ['assessment' => $a, 'tab' => 'odp', 'version' => $r['odp']->id]) : null, $r['odp'] ? route('downloads.document', $r['odp']) : null],
                                ] as [$kind, $icon, $label, $file, $read, $download])
                                    <li class="flex flex-wrap items-center gap-sm px-sm py-2 text-[0.875rem]">
                                        <span class="material-symbols-outlined text-[1.25rem] {{ $file ? 'text-primary' : 'text-on-surface-variant' }}" aria-hidden="true">{{ $icon }}</span>
                                        <span class="flex-1 min-w-40">
                                            <span class="block font-semibold">{{ $label }}</span>
                                            @if ($file)
                                                <span class="block text-[0.8125rem] text-on-surface-variant break-words">
                                                    {{ $file->original_name }}{{ $kind !== 'form' ? ' · version '.$file->versionNumber() : '' }} · approved {{ $file->approved_at->format('j M Y') }}
                                                </span>
                                            @else
                                                <span class="block text-[0.8125rem] text-on-surface-variant">Not approved yet</span>
                                            @endif
                                        </span>
                                        @if ($kind === 'odp' && $file)
                                            @if ($r['signature'])
                                                <x-chip tone="good" icon="verified">Validated · {{ $r['signature']->signed_at->format('j M Y') }}</x-chip>
                                            @else
                                                <x-chip tone="neutral" icon="gpp_maybe">Not validated</x-chip>
                                            @endif
                                        @endif
                                        @if ($file)
                                            <span class="flex items-center gap-sm">
                                                <a href="{{ $read }}" class="text-primary underline">Read</a>
                                                <a href="{{ $download }}" class="inline-flex items-center gap-xs text-primary" aria-label="Download the {{ $label }} of {{ $movement->name }}, {{ $a->period_label }}">
                                                    <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">download</span><span class="underline">Download</span>
                                                </a>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @if ($i > 0 && $loop->last)
                                </div>
                            </details>
                        @endif
                    @empty
                        <p class="text-[0.875rem] text-on-surface-variant">Nothing approved yet.</p>
                    @endforelse
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
