{{-- The annual review form (write, or correct). Expects $assessment, $review (or null), $control. --}}
<form method="POST" action="{{ route('follow-up.review', $assessment) }}" class="flex flex-col gap-sm p-sm rounded bg-surface-container-low">
    @csrf
    <x-field label="What was achieved" size="sm">
        <textarea name="achieved" rows="3" required maxlength="10000" @class([...$control, 'w-full' => true])>{{ old('achieved', $review?->achieved) }}</textarea>
    </x-field>
    <x-field label="What was not achieved, and why" size="sm">
        <textarea name="not_achieved" rows="3" required maxlength="10000" @class([...$control, 'w-full' => true])>{{ old('not_achieved', $review?->not_achieved) }}</textarea>
    </x-field>
    <x-field label="Lessons for the next ODP (optional)" size="sm">
        <textarea name="lessons" rows="2" maxlength="10000" @class([...$control, 'w-full' => true])>{{ old('lessons', $review?->lessons) }}</textarea>
    </x-field>
    <p class="text-[0.8125rem] text-on-surface-variant">The task counts are kept as they stand now. The movement and the other assessors are told.</p>
    <x-button size="sm" icon="save" class="self-start">{{ $review ? 'Save the correction' : 'Save the review' }}</x-button>
</form>
