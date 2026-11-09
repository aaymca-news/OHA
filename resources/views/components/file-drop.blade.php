@props(['name', 'accept', 'prompt', 'label', 'required' => true])
{{--
    A box to drop a file on, or click to choose one (see fileDrop in app.js): the file is
    put in the form's own input, its type and size checked at once, and any problem said
    beside the box. Without scripts it is a plain file input.
--}}
@php($maxKb = \App\Support\UploadLimit::kb())
<div x-data="fileDrop({ accept: @js($accept), maxKb: @js($maxKb), required: @js((bool) $required), label: @js($label) })" class="flex flex-col gap-xs">
    <label x-on:dragenter.prevent="over = true" x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="drop($event)"
           :class="problem ? 'border-error bg-error-container' : (over ? 'border-primary bg-surface-container-low' : 'border-outline-variant')"
           class="flex flex-col items-center gap-xs p-lg rounded-lg border-2 border-dashed text-[0.875rem] cursor-pointer text-center">
        <span class="material-symbols-outlined text-[2rem] text-primary" aria-hidden="true" x-text="name ? 'task' : 'upload_file'">upload_file</span>
        <span class="font-semibold" x-show="name" x-text="name" x-cloak></span>
        <span x-show="! name">{{ $prompt }}</span>
        <span class="text-[0.8125rem] text-on-surface-variant" x-text="name ? 'Click to choose another file.' : @js('Up to '.\App\Support\UploadLimit::label().'.')">Up to {{ \App\Support\UploadLimit::label() }}.</span>
        <input x-ref="input" type="file" name="{{ $name }}" accept="{{ $accept }}" @required($required)
               x-bind:required="false" class="sr-only" x-on:change="check()">
    </label>
    <p x-show="problem" x-cloak role="alert" class="flex items-start gap-xs text-[0.875rem] text-error">
        <span class="material-symbols-outlined text-[1.125rem]" aria-hidden="true">error</span>
        <span x-text="problem"></span>
    </p>
</div>
