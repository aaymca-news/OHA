@props(['variant' => 'primary'])

<button {{ $attributes->merge(['type' => 'submit'])->class([
    'px-md py-2 rounded text-[0.875rem] font-semibold disabled:opacity-40 disabled:cursor-not-allowed',
    'bg-primary text-on-primary hover:bg-primary-container' => $variant === 'primary',
    'border-[1.5px] border-outline-variant text-primary hover:border-primary bg-surface-container-lowest' => $variant === 'secondary',
    'border-[1.5px] border-error text-error hover:bg-error-container bg-surface-container-lowest' => $variant === 'danger',
]) }}>
    {{ $slot }}
</button>
