<?php

/*
|--------------------------------------------------------------------------
| AI Company Card (docs/specs/ai-company-card.md)
|--------------------------------------------------------------------------
|
| On-demand company cards written by an OpenAI-compatible Chat Completions
| provider. External calls are opt-in: the feature stays "not configured"
| until `enabled` is true and the base URL, API key and model are all set.
| The key lives only in the server environment (never Git, browser or logs).
| Technical market context is sent only when `rights_verified` is true; see
| docs/ai-company-card-operations.md before enabling either flag.
|
*/

$temperature = env('AI_TEMPERATURE', 0.2);

return [
    'enabled' => (bool) env('AI_CARD_ENABLED', false),
    'rights_verified' => (bool) env('AI_CARD_MARKET_DATA_RIGHTS_VERIFIED', false),

    // e.g. https://api.openai.com/v1. Must be https://, or http:// only for
    // localhost / 127.0.0.1 (local LLM servers); anything else is treated as
    // not configured.
    'base_url' => env('AI_BASE_URL', ''),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL'),

    // Synchronous call: keep it below nginx's default 60 s read timeout.
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 40),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 1800),
    // `max_tokens`, or `max_completion_tokens` for newer OpenAI models.
    'max_tokens_param' => env('AI_MAX_TOKENS_PARAM', 'max_tokens'),
    // `null` (or an empty value) omits the parameter.
    'temperature' => $temperature === null || $temperature === '' ? null : (float) $temperature,
    // `json_schema` (strict) or `json_object`.
    'response_format' => env('AI_RESPONSE_FORMAT', 'json_schema'),
    // Retry once with `json_object` when the provider answers 400 to json_schema.
    'response_format_fallback' => (bool) env('AI_RESPONSE_FORMAT_FALLBACK', true),

    'freshness_days' => (int) env('AI_CARD_FRESHNESS_DAYS', 7),
    'requests_per_minute' => (int) env('AI_CARD_REQUESTS_PER_MINUTE', 5),
    'user_daily_limit' => (int) env('AI_CARD_USER_DAILY_LIMIT', 10),
    'daily_limit' => (int) env('AI_CARD_DAILY_LIMIT', 200),
    'log_retention_days' => (int) env('AI_CARD_LOG_RETENTION_DAYS', 90),

    // Bump when the schema or the prompt changes (CONSTRAINTS.md).
    'schema_version' => 'company-card-v1',
    'prompt_version' => 'company-card-prompt-v1',
];
