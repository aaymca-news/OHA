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
<li @class(['flex flex-col gap-xs rounded-xl border p-md', 'border-[#ebe9e4] bg-surface-container-lowest' => $q['documents'] !== [], 'border-[#f1e2bd] bg-warning-wash/40' => $q['documents'] === []])>
    <div class="flex flex-wrap items-baseline gap-x-sm gap-y-xs">
        <span class="text-on-surface-variant">{{ EvidenceQuestions::shown($q['code']) }}</span>
        <span class="font-semibold flex-1 min-w-[min(12rem,100%)]">{{ $q['label'] }}</span>
        <x-chip tone="neutral" :icon="$kindIcon">{{ $kindLabel }}</x-chip>
        @if ($q['documents'] === [])
            <x-status-pill tone="warning">Missing</x-status-pill>
        @else
            <x-status-pill tone="good">Supplied</x-status-pill>
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
            <a href="{{ $addLink }}" class="inline-flex items-center gap-[6px] min-h-9 rounded-[10px] border border-on-surface bg-surface-container-lowest px-[12px] font-semibold hover:bg-surface-container-low">
                <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">add_circle</span>
                Add the {{ $q['accepts'] === EvidenceQuestions::LINK ? 'link' : ($q['accepts'] === EvidenceQuestions::EITHER ? 'document or link' : 'document') }}
            </a>
        @endif
    </div>
</li>
