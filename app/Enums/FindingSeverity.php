<?php

namespace App\Enums;

/**
 * How serious a finding from the OHA form check is.
 */
enum FindingSeverity: string
{
    case Error = 'error';
    case Missing = 'missing';
    case Warning = 'warning';

    /** Its chip tone (see x-chip). */
    public function tone(): string
    {
        return match ($this) {
            self::Error => 'critical',
            self::Missing => 'serious',
            self::Warning => 'warning',
        };
    }

    /** Its chip icon. */
    public function icon(): string
    {
        return match ($this) {
            self::Error => 'block',
            self::Missing => 'playlist_remove',
            self::Warning => 'visibility',
        };
    }

    /** Most serious first, for sorting a list of findings. */
    public function rank(): int
    {
        return match ($this) {
            self::Error => 0,
            self::Missing => 1,
            self::Warning => 2,
        };
    }

    /** Its label, as shown on the chip. */
    public function label(): string
    {
        return __('oha.severity.'.$this->value);
    }
}
