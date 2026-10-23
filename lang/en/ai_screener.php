<?php

// Natural-language Screener API messages (ai-screener-natural-language).
// Keys are the stable `code` values returned with the error; status codes
// never depend on the language.
return [
    'provider_not_configured' => 'Describing a search in your own words is not available yet.',
    'provider_unavailable' => 'The AI provider is unavailable. Try again later or use the filters.',
    'invalid_provider_response' => 'The AI could not turn your request into filters. Nothing was changed; rephrase it or use the filters.',
    'daily_limit_reached' => 'The daily limit of AI searches has been reached. Try again tomorrow or use the filters.',
    'user_daily_limit' => 'You have reached your daily limit of AI searches. Try again tomorrow or use the filters.',
    // Appended to `unsupported` when the answer used the sector filters and
    // the account has no Market Context access (market-context-patterns, C7).
    'market_context_unavailable' => 'Sector context filters (not available for your account)',
];
