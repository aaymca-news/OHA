{{--
    A text area with its label, hint and error. The text is old($name), else `value`.
    Attributes go to the <textarea>; `wrap` adds classes to the label around it.
      size  md (default) · sm
--}}
@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'size' => 'md', 'rows' => 3, 'wrap' => null])
@php
    $id = $attributes->get('id') ?? 'textarea-'.str_replace(['[', ']'], '-', $name).'-'.\Illuminate\Support\Str::random(6);
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :size="$size" :for="$id" :class="$wrap">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" {{ $attributes->except('id')->class(\App\Support\Ui::control($errors->has($name), $size)) }}>{{ old($name, $value) }}</textarea>
</x-field>
