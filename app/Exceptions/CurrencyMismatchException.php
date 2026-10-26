<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the engine reports a currency other than the Instrument's own,
 * which signals a wrong symbol mapping (for example a US line for a European
 * ticker). Nothing is stored for the Instrument and the ledger item fails.
 */
class CurrencyMismatchException extends RuntimeException
{
    public static function forTicker(string $ticker, string $expected, string $reported): self
    {
        return new self("currency_mismatch: engine reported {$reported} for [{$ticker}], expected {$expected}; no bars stored.");
    }
}
