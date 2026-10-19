<?php

/*
|--------------------------------------------------------------------------
| Natural-language Screener (docs/specs/ai-screener-natural-language.md)
|--------------------------------------------------------------------------
|
| Turns a Registered User's free-text request into the existing Screener
| filters. It shares the OpenAI-compatible provider of the AI Company Card
| (same AI_BASE_URL / AI_API_KEY / AI_MODEL) through
| `new OpenAiCompatibleClient('ai_screener')`, but has its own opt-in flag,
| limits and versions. The query text is never stored or logged.
|
*/

return [
    'enabled' => (bool) env('AI_SCREENER_ENABLED', false),

    // Shared provider settings (see config/ai_card.php for the URL rules).
    'base_url' => env('AI_BASE_URL', ''),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL'),

    // A short structured answer: a small token budget and a short timeout.
    'timeout_seconds' => (int) env('AI_SCREENER_TIMEOUT_SECONDS', 20),
    'max_tokens' => (int) env('AI_SCREENER_MAX_TOKENS', 800),
    'max_tokens_param' => env('AI_MAX_TOKENS_PARAM', 'max_tokens'),
    'temperature' => 0.0,
    'response_format' => env('AI_RESPONSE_FORMAT', 'json_schema'),
    'response_format_fallback' => (bool) env('AI_RESPONSE_FORMAT_FALLBACK', true),

    'requests_per_minute' => (int) env('AI_SCREENER_REQUESTS_PER_MINUTE', 6),
    'user_daily_limit' => (int) env('AI_SCREENER_USER_DAILY_LIMIT', 30),
    'daily_limit' => (int) env('AI_SCREENER_DAILY_LIMIT', 600),
    'log_retention_days' => (int) env('AI_SCREENER_LOG_RETENTION_DAYS', 90),
    // Identical requests (normalized text + locale + versions) reuse the
    // validated answer from the default cache store for this long.
    'cache_ttl_hours' => 24,

    // Bump when the schema or the prompt changes (CONSTRAINTS.md); a bump
    // also invalidates the cache.
    'schema_version' => 'screener-query-v1',
    'prompt_version' => 'screener-query-prompt-v1',
];
