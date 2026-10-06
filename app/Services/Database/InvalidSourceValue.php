<?php

namespace App\Services\Database;

use RuntimeException;

/**
 * A source value that the PostgreSQL column would reject or silently alter.
 *
 * The message names the column and the reason only; the offending value is
 * never included so copy reports cannot leak user data.
 */
class InvalidSourceValue extends RuntimeException
{
    public function __construct(public readonly string $column, public readonly string $reason)
    {
        parent::__construct("column [{$column}]: {$reason}");
    }
}
