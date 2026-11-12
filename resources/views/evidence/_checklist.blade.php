{{--
    The evidence checklist: the form's "Yes" answers that evidence can prove, by category,
    each with exactly what is needed and the evidence linked to it, or "Missing". Expects
    $checklist (from EvidenceLibrary::checklist) and $categoryNames (code => name).
--}}
@php($needed = collect($checklist)->flatten(1))
@php($backed = $needed->filter(fn ($q) => $q['documents'] !== [])->count())
@if ($needed->isEmpty())
    <x-empty-state icon="fact_check">None of the form’s “Yes” answers calls for evidence.</x-empty-state>
@else
    <p class="text-[0.875rem]">
        <strong>{{ $backed }} of {{ $needed->count() }}</strong> “Yes” answers that call for evidence have it.
    </p>
    <div class="flex flex-col divide-y divide-[#f2f1ec] rounded-xl border border-[#ebe9e4]">
        @foreach ($checklist as $category => $questions)
            @php($missing = collect($questions)->where('documents', [])->count())
            <details class="text-[0.875rem]">
                <summary class="cursor-pointer px-md py-[10px] flex flex-wrap items-center gap-sm hover:bg-[#fafaf8]">
                    <span class="font-semibold flex-1">{{ $categoryNames[$category] ?? $category }}</span>
                    @if ($missing > 0)
                        <x-status-pill tone="warning">{{ $missing }} missing</x-status-pill>
                    @else
                        <x-status-pill tone="good">All supplied</x-status-pill>
                    @endif
                </summary>
                <ul class="flex flex-col gap-sm px-md pb-md">
                    @foreach ($questions as $q)
                        @include('evidence._requirement', ['q' => $q, 'addLink' => null])
                    @endforeach
                </ul>
            </details>
        @endforeach
    </div>
@endif
