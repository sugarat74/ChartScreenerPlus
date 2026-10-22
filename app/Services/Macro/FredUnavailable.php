<?php

namespace App\Services\Macro;

use RuntimeException;

/**
 * A FRED request (or its validation) that produced no usable data. The
 * message is the code only, never a URL, body or key. Codes
 * (docs/specs/macro-series-fred.md, Domain Rules 3-4):
 * `invalid_key` (400 naming `api_key`; stops the whole run), `bad_request`
 * (other 400), `not_found` (404), `rate_limited` (429 after the retries),
 * `unavailable` (5xx or connection error after the retries),
 * `invalid_response` (not JSON, missing keys, bad date or value),
 * `http_error` (any other status; redirects are never followed) and
 * `units_changed` (a liquidity series no longer published in millions).
 */
class FredUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct($reason);
    }
}
