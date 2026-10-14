<?php

return [
    // External calls are opt-in. Enable only after confirming market-data
    // transfer rights and setting a concrete provider model plus API key.
    'enabled' => (bool) env('JEV_ENABLED', false),
    'rights_verified' => (bool) env('JEV_MARKET_DATA_RIGHTS_VERIFIED', false),
    'api_key' => env('TYPESAFE_API_KEY'),
    'model' => env('JEV_MODEL'),
    'question_version' => 'close-direction-v1',
    'endpoint' => 'https://api.typesafe.ai/v1/systemone',
    'history_bars' => 252,
    'minimum_bars' => 50,
    'horizons' => [1, 5],
    'requests_per_minute' => 10,
    'timeout_seconds' => 8,
];
