{{--
    The text size slider, in the account menu and on My profile: from 90% to 140% of the
    reader's own browser setting, remembered on this device. Uses the page's "layout" data.
    Expects $id (unique on the page).
--}}
<div class="flex flex-col gap-xs">
    <label for="{{ $id }}" class="flex items-baseline justify-between gap-sm text-[0.8125rem] font-semibold text-on-surface-variant">
        <span>Text size</span>
        <span class="tabular-nums text-on-surface" x-text="textScale + '%'">100%</span>
    </label>
    <div class="flex items-center gap-sm">
        <button type="button" x-on:click="setTextScale(textScale - 5)" :disabled="textScale <= 90" aria-label="Smaller text"
                class="w-8 h-8 shrink-0 grid place-items-center rounded border-[1.5px] border-outline-variant hover:border-primary font-bold text-[0.8125rem] disabled:opacity-40">A</button>
        <input id="{{ $id }}" type="range" min="90" max="140" step="5" :value="textScale" value="100"
               x-on:input="setTextScale(Number($event.target.value))" :aria-valuetext="textScale + ' percent'"
               class="flex-1 min-w-0 accent-primary">
        <button type="button" x-on:click="setTextScale(textScale + 5)" :disabled="textScale >= 140" aria-label="Larger text"
                class="w-8 h-8 shrink-0 grid place-items-center rounded border-[1.5px] border-outline-variant hover:border-primary font-bold text-[1.125rem]">A</button>
    </div>
    <button type="button" x-show="textScale !== 100" x-cloak x-on:click="setTextScale(100)" class="self-start text-[0.8125rem] text-primary underline">Back to normal size</button>
</div>
