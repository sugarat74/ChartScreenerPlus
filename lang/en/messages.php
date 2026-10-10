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
    'user_not_found' => 'User not found.',
    'session_not_found' => 'Session not found or already ended.',
    'session_is_current' => 'You cannot end the session you are using right now.',

    // Framework-generated API errors (App\Http\LocalizedFrameworkMessages).
    'http' => [
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'This action is unauthorized.',
        'not_found' => 'The requested resource was not found.',
        'method_not_allowed' => 'This method is not allowed for the requested resource.',
        'session_expired' => 'Your session has expired. Reload the page and try again.',
        'too_many_attempts' => 'Too Many Attempts.',
        'server_error' => 'Server Error',
        'service_unavailable' => 'Service temporarily unavailable. Please try again later.',
    ],

    'ingestion' => [
        'universe_unknown' => 'No universe found for [:slug].',
        'run_not_finished' => 'Only a finished run can be retried.',
        'run_without_failures' => 'This run has no failed instruments to retry.',
    ],

    'screener' => [
        'signal_unknown_types' => 'The signal parameter must contain only known signal types.',
        'signal_invalid' => 'The selected signal type is invalid.',
        'pattern_invalid' => 'The selected pattern type is invalid.',
        'pattern_status_invalid' => 'The pattern status must be any, forming or confirmed.',
        'parameter_number' => 'The :parameter parameter must be a number.',
        'parameter_boolean' => 'The :parameter parameter must be a boolean (1/true/on/yes or 0/false/off/no).',
        'ma_cross_invalid' => 'The ma_cross parameter must be one of: bullish, bearish.',
        'sort_invalid' => 'The sort parameter must be one of: :values.',
    ],
];
