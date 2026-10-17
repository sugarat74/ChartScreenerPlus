<?php

namespace App\Services\Fundamentals;

use RuntimeException;

/**
 * A SEC request that did not produce usable JSON. `reason` is one of:
 * `forbidden` (403: undeclared/blocked User-Agent, stops the whole run),
 * `not_found` (404: no data for this CIK), `unavailable` (429/5xx/timeout
 * after the bounded retries), `http_error` (other status, including a
 * redirect, which is never followed) or `invalid_response` (not JSON).
 */
class SecUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct($status === null ? $reason : "{$reason} ({$status})");
    }
}
