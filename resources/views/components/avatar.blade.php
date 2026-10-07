{{-- A person's profile photo, or their initials when they have none. Decorative: their name is always shown beside it. --}}
@props(['user', 'size' => 'md'])
@php
    $box = [
        'sm' => 'w-7 h-7 text-[0.8125rem]',
        'md' => 'w-8 h-8 text-[0.8125rem]',
        'lg' => 'w-20 h-20 text-[1.5rem]',
    ][$size] ?? 'w-8 h-8 text-[0.8125rem]';
    $photo = $user?->avatarUrl();
@endphp
@if ($photo)
    <img src="{{ $photo }}" alt="" aria-hidden="true" loading="lazy" decoding="async"
         {{ $attributes->class([$box, 'rounded-full object-cover shrink-0 bg-surface-container border border-outline-variant']) }}>
@else
    <span aria-hidden="true" {{ $attributes->class([$box, 'rounded-full bg-primary-container text-on-primary font-bold flex items-center justify-center shrink-0']) }}>{{ $user?->initials() }}</span>
@endif
