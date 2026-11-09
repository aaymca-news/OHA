{{--
    A label around any control: its text, the control (the slot), a hint, and what was wrong
    with it (from the validation errors for $name). x-input, x-select and x-textarea use it;
    use it directly for anything else (a group of checkboxes, a custom control).
      size  md (default) · sm (filter bars)
--}}
@props(['label' => null, 'name' => null, 'hint' => null, 'size' => 'md', 'for' => null])
<label {{ $attributes->class(\App\Support\Ui::field($size)) }} @if ($for) for="{{ $for }}" @endif>
    @if ($label !== null)
        <span @class(['font-semibold', 'text-on-surface' => $size !== 'sm'])>{{ $label }}</span>
    @endif
    {{ $slot }}
    @if ($hint)
        <span class="text-[0.8125rem] text-on-surface-variant">{{ $hint }}</span>
    @endif
    {{-- What was wrong with this box, said under it (the page's summary at the top says it too). --}}
    @if ($name !== null)
        @error($name)
            <span class="flex items-start gap-xs text-[0.8125rem] text-error"><span class="material-symbols-outlined text-[1rem]" aria-hidden="true">error</span>{{ $message }}</span>
        @enderror
    @endif
</label>
