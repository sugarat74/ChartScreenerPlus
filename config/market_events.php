<?php

// Dates only. Sources checked 2026-11-10; see docs/specs/market-events-calendar.md.
return [
    'schedule_time' => '06:45',
    'past_days' => 30,
    'future_days' => 90,
    'stale_after_days' => 3,
    'releases' => [
        'cpi' => ['id' => 10, 'source' => 'U.S. Bureau of Labor Statistics, via FRED'],
        'employment' => ['id' => 50, 'source' => 'U.S. Bureau of Labor Statistics, via FRED'],
        'gdp' => ['id' => 53, 'source' => 'U.S. Bureau of Economic Analysis, via FRED'],
        'pce' => ['id' => 54, 'source' => 'U.S. Bureau of Economic Analysis, via FRED'],
        'retail' => ['id' => 9, 'source' => 'U.S. Census Bureau, via FRED'],
    ],
];
