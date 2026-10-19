<?php

namespace App\Services\Screener\Ai;

use RuntimeException;

/**
 * A request that cannot be interpreted now. `reason` is a stable,
 * locale-independent code mapped to `{code, message}` by the controller
 * (`lang/{es,en}/ai_screener.php`), mirroring `CompanyCardUnavailable`.
 */
class ScreenerQueryUnavailable extends RuntimeException
{
    public const STATUS = [
        'user_daily_limit' => 429,
        'daily_limit_reached' => 503,
        'provider_not_configured' => 503,
        'provider_unavailable' => 503,
        'invalid_provider_response' => 503,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function status(): int
    {
        return self::STATUS[$this->reason] ?? 503;
    }
}
