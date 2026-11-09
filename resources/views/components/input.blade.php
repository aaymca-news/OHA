{{--
    A text box with its label, hint and error. Attributes go to the <input>; `wrap` adds
    classes to the label around it.
      size  md (default) · sm (filter bars)
    type="password" adds a show/hide toggle.
--}}
@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'size' => 'md', 'wrap' => null])
@php
    $classes = \App\Support\Ui::control($errors->has($name), $size);
    $id = $attributes->get('id') ?? 'input-'.$name.'-'.\Illuminate\Support\Str::random(6);
@endphp

<x-field :label="$label" :name="$name" :hint="$hint" :size="$size" :for="$id" :class="$wrap">
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
                {{ $attributes->except('id')->class([...$classes, 'w-full pr-11' => true]) }}
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
</x-field>
