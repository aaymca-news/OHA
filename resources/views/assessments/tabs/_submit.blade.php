{{--
    Submitting the newest version of the report or ODP for approval. An Administrator approves
    their own work directly (in _decisions), so is not asked to submit it.
    Expects $artefact and $number (the newest version's number).
--}}
@if (Gate::allows('submit', $artefact) && Gate::denies('approve', $artefact))
    <form method="POST" action="{{ route('artefacts.submit', $artefact) }}" class="flex flex-wrap items-center gap-sm">
        @csrf
        <input type="hidden" name="seen" value="{{ \App\Support\ReplaceGuard::current($artefact)?->id ?? 0 }}">
        <x-button>Submit version {{ $number }} for approval</x-button>
        <span class="text-[0.8125rem] text-on-surface-variant">It goes to the Administrators to approve.</span>
    </form>
@endif
