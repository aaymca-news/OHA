{{--
    One "Yes" answer that evidence can prove: the question, exactly what is needed, whether
    it takes a document or a link, and what has been supplied, or "Missing" with a way to
    add it. Expects $q (a checklist item) and $addLink (where "Add" goes, or null).
--}}
@use('App\Oha\EvidenceQuestions')
@php($kinds = [
    EvidenceQuestions::DOCUMENT => ['description', 'Document'],
    EvidenceQuestions::LINK => ['link', 'Link'],
    EvidenceQuestions::EITHER => ['attach_file', 'Document or link'],
])
@php([$kindIcon, $kindLabel] = $kinds[$q['accepts']])
<li @class(['flex flex-col gap-xs p-sm rounded border-l-4', 'border-band-strong bg-surface-container-lowest' => $q['documents'] !== [], 'border-warning-ink bg-warning-wash/40' => $q['documents'] === []])>
    <div class="flex flex-wrap items-baseline gap-x-sm gap-y-xs">
        <span class="text-on-surface-variant">{{ EvidenceQuestions::shown($q['code']) }}</span>
        <span class="font-semibold flex-1 min-w-[min(12rem,100%)]">{{ $q['label'] }}</span>
        <x-chip tone="neutral" :icon="$kindIcon">{{ $kindLabel }}</x-chip>
        @if ($q['documents'] === [])
            <x-chip tone="warning" icon="pending">Missing</x-chip>
        @else
            <x-chip tone="good" icon="task_alt">Supplied</x-chip>
        @endif
    </div>
    <p class="text-[0.8125rem] text-on-surface-variant">Needed: {{ $q['needed'] }}</p>
    @if ($why = \App\Oha\WhyItMatters::for($q['code']))
        <p class="text-[0.8125rem] text-on-surface-variant"><span class="font-semibold">Why it matters:</span> {{ $why }}</p>
    @endif
    <div class="flex flex-wrap items-center gap-sm text-[0.875rem]">
        @foreach ($q['documents'] as $doc)
            <x-evidence-link :doc="$doc" />
        @endforeach
        @if ($q['documents'] === [] && $addLink)
            <a href="{{ $addLink }}" class="inline-flex items-center gap-xs text-primary font-semibold underline">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">add_circle</span>
                Add the {{ $q['accepts'] === EvidenceQuestions::LINK ? 'link' : ($q['accepts'] === EvidenceQuestions::EITHER ? 'document or link' : 'document') }}
            </a>
        @endif
    </div>
</li>
