<?php

namespace App\Enums;

/**
 * Where an ODP task stands, as reported in Stage 2 (ODP follow-up). Done counts only
 * once an assessor has confirmed it (OdpTask::$done).
 */
enum TaskProgress: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Blocked => 'Blocked',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NotStarted => 'radio_button_unchecked',
            self::InProgress => 'progress_activity',
            self::Done => 'task_alt',
            self::Blocked => 'block',
        };
    }

    /** Its chip tone (see x-chip). */
    public function tone(): string
    {
        return match ($this) {
            self::NotStarted => 'neutral',
            self::InProgress => 'info',
            self::Done => 'good',
            self::Blocked => 'serious',
        };
    }

    /** @return array<string, string> value => label, for a dropdown */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}
