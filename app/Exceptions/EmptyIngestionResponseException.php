<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the engine returns no EOD bars for an instrument. The single
 * instrument command treats this as a failure and the run ledger records it as
 * a failed item (never as a success with zero bars).
 */
class EmptyIngestionResponseException extends RuntimeException
{
    public static function forTicker(string $ticker): self
    {
        return new self("Engine returned no EOD bars for [{$ticker}].");
    }
}
