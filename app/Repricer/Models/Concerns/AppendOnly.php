<?php

namespace App\Repricer\Models\Concerns;

use LogicException;

/**
 * Audit rows are written once and never changed. (Postgres triggers enforce the same rule.)
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only: updates are not allowed.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only: deletes are not allowed.'));
    }
}
