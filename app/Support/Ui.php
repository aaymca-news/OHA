<?php

namespace App\Support;

/**
 * Shared class lists for the form controls (x-input, x-select, x-textarea), so every box
 * on every page has the same border, focus and error look.
 */
final class Ui
{
    /**
     * A text box, dropdown or text area. "sm" is the compact size used in filter bars.
     *
     * @return array<string, bool>
     */
    public static function control(bool $invalid = false, string $size = 'md'): array
    {
        return [
            'rounded border-[1.5px] bg-surface-container-lowest focus:border-primary outline-none' => true,
            'px-sm py-2' => $size !== 'sm',
            'px-sm py-1.5 text-[0.875rem]' => $size === 'sm',
            'border-error' => $invalid,
            'border-outline-variant' => ! $invalid,
        ];
    }

    /**
     * The label around a control: its text above, then the control, hint and error.
     *
     * @return array<string, bool>
     */
    public static function field(string $size = 'md'): array
    {
        return [
            'flex flex-col gap-xs' => true,
            'text-[0.875rem]' => $size !== 'sm',
            'text-[0.8125rem]' => $size === 'sm',
        ];
    }
}
