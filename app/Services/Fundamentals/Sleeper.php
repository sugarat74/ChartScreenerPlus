<?php

namespace App\Services\Fundamentals;

/**
 * Clock and sleep used by `SecEdgarClient` for request spacing and retry
 * backoff. Tests bind a fake that records the waits and advances its own
 * clock, so rate limiting is asserted without real sleeping.
 */
class Sleeper
{
    /** Monotonic time in seconds. */
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
