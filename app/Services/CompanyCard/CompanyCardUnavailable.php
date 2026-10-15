<?php

namespace App\Services\CompanyCard;

use RuntimeException;

/**
 * A card that cannot be produced now. `reason` is a stable code mapped to
 * `{code, message}` by the controller (`lang/{es,en}/company_card.php`).
 */
class CompanyCardUnavailable extends RuntimeException
{
    public const STATUS = [
        'user_daily_limit' => 429,
        'provider_not_configured' => 503,
        'provider_unavailable' => 503,
        'invalid_provider_response' => 503,
        'busy' => 503,
        'daily_limit_reached' => 503,
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
