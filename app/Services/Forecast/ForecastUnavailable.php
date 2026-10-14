<?php

namespace App\Services\Forecast;

use RuntimeException;

class ForecastUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
