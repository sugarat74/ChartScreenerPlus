<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an incoming London (`XLON`, pence/GBX) series differs from the
 * Instrument's stored closes by a ratio that only a pence/pound unit flip
 * produces (docs/specs/universes-cac40-ftse100.md, P1 scale-jump guard).
 * Nothing is stored for the Instrument and the ledger item fails.
 */
class PriceScaleMismatchException extends RuntimeException
{
    public static function forTicker(string $ticker, string $date, float $stored, float $incoming): self
    {
        return new self(sprintf(
            'price_scale_mismatch: engine close %s for [%s] on %s differs from the stored close %s by 40x or more (suspected pence/pound change); no bars stored.',
            rtrim(rtrim(number_format($incoming, 4, '.', ''), '0'), '.'),
            $ticker,
            $date,
            rtrim(rtrim(number_format($stored, 4, '.', ''), '0'), '.'),
        ));
    }
}
