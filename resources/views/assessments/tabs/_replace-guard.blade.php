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
    <x-callout tone="warning" x-data="{ replace: {{ old('replace') ? 'true' : 'false' }} }">
        <p>
            <span class="font-semibold">{{ ucfirst(\App\Support\ReplaceGuard::describe($current)) }} was saved by {{ \App\Support\ReplaceGuard::savedBy($current) }}.</span>
            Uploading makes yours the current one. {{ $theirs ? 'Theirs' : 'The earlier one' }} is kept, and becomes current again if yours is withdrawn.
        </p>
        <label class="flex items-center gap-sm font-semibold">
            <input type="hidden" name="replace" value="0">
            <input type="checkbox" name="replace" value="1" x-model="replace" @checked(old('replace'))>
            I want to replace it
        </label>
        @if ($theirs)
            <x-field label="Why are you replacing it?" name="replace_reason" x-show="replace" x-cloak
                     :hint="\Illuminate\Support\Str::before(\App\Support\ReplaceGuard::savedBy($current), ',').' and the other assessors see this in the audit trail.'">
                <textarea name="replace_reason" rows="2" maxlength="2000" x-bind:required="replace"
                          @class([...\App\Support\Ui::control($errors->has('replace_reason')), 'text-on-surface' => true])>{{ old('replace_reason') }}</textarea>
            </x-field>
        @endif
    </x-callout>
@endif
