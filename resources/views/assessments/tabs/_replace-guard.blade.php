{{--
    Inside an upload form for the OHA form, the report or the ODP ($artefact): which version
    the page showed (so a newer one saved meanwhile stops the upload), and, when one is
    current, who saved it and when, a tick to confirm replacing it, and the reason when it
    is a colleague's. The one replaced is kept, and comes back if the new one is withdrawn.
--}}
@php
    $current = \App\Support\ReplaceGuard::current($artefact);
    $theirs = $current !== null && \App\Support\ReplaceGuard::savedById($current) !== auth()->id();
@endphp
<input type="hidden" name="seen" value="{{ $current?->id ?? 0 }}">
@if ($current)
    <div class="flex flex-col gap-sm p-md rounded-lg bg-warning-wash text-warning-ink text-[0.875rem]" x-data="{ replace: @js((bool) old('replace')) }">
        <p class="flex items-start gap-sm">
            <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true">warning</span>
            <span>
                <span class="font-semibold">{{ ucfirst(\App\Support\ReplaceGuard::describe($current)) }} was saved by {{ \App\Support\ReplaceGuard::savedBy($current) }}.</span>
                Uploading makes yours the current one. {{ $theirs ? 'Theirs' : 'The earlier one' }} is kept, and becomes current again if yours is withdrawn.
            </span>
        </p>
        <label class="flex items-center gap-sm font-semibold">
            <input type="hidden" name="replace" value="0">
            <input type="checkbox" name="replace" value="1" x-model="replace" @checked(old('replace'))>
            I want to replace it
        </label>
        @if ($theirs)
            <label class="flex flex-col gap-xs" x-show="replace" x-cloak>
                <span class="font-semibold">Why are you replacing it?</span>
                <textarea name="replace_reason" rows="2" maxlength="2000" x-bind:required="replace"
                          class="px-sm py-2 rounded border-[1.5px] border-outline-variant bg-surface-container-lowest text-on-surface">{{ old('replace_reason') }}</textarea>
                <span class="text-[0.8125rem]">{{ \Illuminate\Support\Str::before(\App\Support\ReplaceGuard::savedBy($current), ',') }} and the other assessors see this in the audit trail.</span>
            </label>
        @endif
    </div>
@endif
