{{--
    A dropdown with its label, hint and error. Give the choices as `options` (value => text),
    or as <option>s in the slot; `placeholder` adds a first, empty choice (such as "All").
    The chosen one is old($name), else `value`. Attributes go to the <select>; `wrap` adds
    classes to the label around it.
      size  md (default) · sm (filter bars)
--}}
@props(['name', 'label' => null, 'options' => null, 'value' => null, 'placeholder' => null, 'hint' => null, 'size' => 'md', 'wrap' => null])
@php
    $chosen = (string) old($name, $value ?? '');
    $id = $attributes->get('id') ?? 'select-'.str_replace(['[', ']'], '-', $name).'-'.\Illuminate\Support\Str::random(6);
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :size="$size" :for="$id" :class="$wrap">
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id')->class(\App\Support\Ui::control($errors->has($name), $size)) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options ?? [] as $key => $text)
            <option value="{{ $key }}" @selected($chosen === (string) $key)>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
</x-field>
