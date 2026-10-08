{{-- Returning submitted work to the assessor, with the reason they work from. Expects $artefact. --}}
<form method="POST" action="{{ route('artefacts.send-back', $artefact) }}" class="flex flex-col gap-sm">
    @csrf
    <label class="flex flex-col gap-xs text-[0.875rem]">
        <span class="font-semibold">Why is it going back? (required)</span>
        <textarea name="reason" rows="3" required class="px-sm py-2 rounded border-[1.5px] border-outline-variant">{{ old('reason') }}</textarea>
    </label>
    <x-button variant="danger" class="self-start">Send back</x-button>
</form>
