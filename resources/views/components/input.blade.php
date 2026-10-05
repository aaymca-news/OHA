@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])
@php
    $classes = [
        'px-sm py-2 rounded border-[1.5px] bg-surface-container-lowest focus:border-primary outline-none',
        'border-error' => $errors->has($name),
        'border-outline-variant' => ! $errors->has($name),
    ];
    $id = $attributes->get('id') ?? 'input-'.$name.'-'.\Illuminate\Support\Str::random(6);
@endphp

<label class="flex flex-col gap-xs text-[0.875rem]" for="{{ $id }}">
    <span class="font-semibold text-on-surface">{{ $label }}</span>
    @if ($type === 'password')
        {{--
            A password box with a show/hide toggle, so people can check what they typed.
            It starts hidden, and stays a normal password box if scripts do not run.
        --}}
        <span class="relative flex" x-data="{ shown: false }">
            <input
                id="{{ $id }}"
                type="password"
                :type="shown ? 'text' : 'password'"
                name="{{ $name }}"
                value=""
                {{ $attributes->except('id')->class([...$classes, 'w-full pr-11']) }}
            >
            <button type="button" x-on:click="shown = ! shown" aria-controls="{{ $id }}"
                    :aria-pressed="shown" :aria-label="shown ? 'Hide password' : 'Show password'" :title="shown ? 'Hide password' : 'Show password'"
                    aria-label="Show password" title="Show password"
                    class="absolute inset-y-0 right-0 w-10 flex items-center justify-center rounded-r text-on-surface-variant hover:text-primary">
                <span class="material-symbols-outlined text-[1.25rem]" aria-hidden="true" x-text="shown ? 'visibility_off' : 'visibility'">visibility</span>
            </button>
        </span>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            name="{{ $name }}"
            value="{{ old($name, $value) }}"
            {{ $attributes->except('id')->class($classes) }}
        >
    @endif
    @if ($hint)
        <span class="text-[0.8125rem] text-on-surface-variant">{{ $hint }}</span>
    @endif
</label>
