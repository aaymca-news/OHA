{{-- Returning submitted work to the assessor, with the reason they work from. Expects $artefact. --}}
<form method="POST" action="{{ route('artefacts.send-back', $artefact) }}" class="flex flex-col gap-sm">
    @csrf
    <x-textarea name="reason" label="Why is it going back? (required)" rows="3" required />
    <x-button variant="danger" class="self-start">Send back</x-button>
</form>
