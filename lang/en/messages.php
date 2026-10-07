<?php

/*
|--------------------------------------------------------------------------
| Chartiko API messages
|--------------------------------------------------------------------------
|
| User-facing messages returned by the API. Keep the same keys in every
| locale (LocalizationTest checks it). Status codes, response shapes,
| `errors` keys and Signal codes never depend on these strings.
|
*/

return [
    'instrument_not_found' => 'Instrument not found.',
    'instrument_unknown' => 'Unknown instrument.',
    'not_in_watchlist' => 'Instrument is not in your watchlist.',
    'screener_not_found' => 'Screener not found.',
    'universe_not_found' => 'Universe not found.',
    'admin_required' => 'Admin access required.',
    'stateful_session_required' => 'A stateful session is required.',

    'ingestion' => [
        'universe_unknown' => 'No universe found for [:slug].',
        'run_not_finished' => 'Only a finished run can be retried.',
        'run_without_failures' => 'This run has no failed instruments to retry.',
    ],

    'screener' => [
        'signal_unknown_types' => 'The signal parameter must contain only known signal types.',
        'signal_invalid' => 'The selected signal type is invalid.',
        'parameter_number' => 'The :parameter parameter must be a number.',
        'parameter_boolean' => 'The :parameter parameter must be a boolean (1/true/on/yes or 0/false/off/no).',
        'ma_cross_invalid' => 'The ma_cross parameter must be one of: bullish, bearish.',
        'sort_invalid' => 'The sort parameter must be one of: :values.',
    ],
];
