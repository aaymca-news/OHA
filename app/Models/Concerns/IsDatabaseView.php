<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * For models over a PostgreSQL view. Views hold derived values, so they are
 * read-only: change the underlying facts, never the view.
 */
trait IsDatabaseView
{
    public static function bootIsDatabaseView(): void
    {
        static::saving(fn () => throw new LogicException(static::class.' reads a database view and cannot be saved.'));
        static::deleting(fn () => throw new LogicException(static::class.' reads a database view and cannot be deleted.'));
    }
}
