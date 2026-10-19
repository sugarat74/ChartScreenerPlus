<?php

namespace App\Services\Screener\Ai;

use App\Models\AiScreenerQuery;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Registered User's free-text request into the existing Screener
 * filters (docs/specs/ai-screener-natural-language.md).
 *
 * Order: configuration -> cache (a hit skips the caps and the provider) ->
 * rolling 24 h caps -> one provider call -> `ScreenerQuerySchema` validation
 * (which also runs `ScreenerFilterParser`) -> usage log and cache store.
 * Anything invalid fails as a whole; nothing is partially applied.
 *
 * Privacy: the query text is never stored or logged. The usage log keeps only
 * metadata (length, outcome, token counts), the cache key is a SHA-256 hash
 * and the cached value holds only the validated response.
 */
class ScreenerQueryInterpreter
{
    private readonly OpenAiCompatibleClient $client;

    public function __construct()
    {
        // Built here, not injected: the container would hand over the default
        // (`ai_card`) client.
        $this->client = new OpenAiCompatibleClient('ai_screener');
    }

    /** Interpretation is enabled and the shared provider is fully configured. */
    public function available(): bool
    {
        return (bool) config('ai_screener.enabled') && $this->client->isConfigured();
    }

    /**
     * @return array{filters: array<string, mixed>, unsupported: list<string>, explanation: string}
     *
     * @throws ScreenerQueryUnavailable
     */
    public function interpret(string $query, string $locale, User $user): array
    {
        if (! $this->available()) {
            throw new ScreenerQueryUnavailable('provider_not_configured');
        }

        $length = mb_strlen($query);
        $cacheKey = self::cacheKey($query, $locale);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['filters'], $cached['unsupported'], $cached['explanation'])) {
            $this->log($user, $locale, $length, AiScreenerQuery::OUTCOME_CACHED, count($cached['unsupported']));

            return $cached;
        }

        $this->enforceCaps($user, $locale, $length);

        try {
            $completion = $this->client->structured(
                ScreenerQueryPrompt::messages($query, $locale),
                ScreenerQuerySchema::NAME,
                ScreenerQuerySchema::schema(),
            );
        } catch (AiProviderException $exception) {
            $outcome = $exception->reason === 'invalid_provider_response'
                ? AiScreenerQuery::OUTCOME_INVALID
                : AiScreenerQuery::OUTCOME_UNAVAILABLE;
            $this->log($user, $locale, $length, $outcome, 0, $exception->usage, $exception->latencyMs);

            throw new ScreenerQueryUnavailable($exception->reason === 'invalid_provider_response' ? 'invalid_provider_response' : 'provider_unavailable');
        }

        try {
            $result = ScreenerQuerySchema::validate($completion->data);
        } catch (InvalidScreenerQuery $exception) {
            Log::warning('AI screener answer rejected by schema validation', ['rule' => $exception->getMessage()]);
            $this->log($user, $locale, $length, AiScreenerQuery::OUTCOME_INVALID, 0, $completion->usage, $completion->latencyMs);

            throw new ScreenerQueryUnavailable('invalid_provider_response');
        }

        $this->log($user, $locale, $length, AiScreenerQuery::OUTCOME_OK, count($result['unsupported']), $completion->usage, $completion->latencyMs);
        Cache::put($cacheKey, $result, now()->addHours(max(1, (int) config('ai_screener.cache_ttl_hours'))));

        return $result;
    }

    /**
     * Opaque cache key: a hash of the normalized text, the locale and the
     * schema and prompt versions. The text itself never appears in the key.
     */
    public static function cacheKey(string $query, string $locale): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))) ?? '';

        return 'ai-screener:'.hash('sha256', implode("\n", [
            $normalized,
            $locale,
            (string) config('ai_screener.schema_version'),
            (string) config('ai_screener.prompt_version'),
        ]));
    }

    private function enforceCaps(User $user, string $locale, int $length): void
    {
        $since = now()->subDay();
        $counted = AiScreenerQuery::query()
            ->whereIn('outcome', AiScreenerQuery::COUNTED_OUTCOMES)
            ->where('created_at', '>=', $since);

        if ((clone $counted)->where('user_id', $user->id)->count() >= max(0, (int) config('ai_screener.user_daily_limit'))) {
            $this->log($user, $locale, $length, AiScreenerQuery::OUTCOME_LIMITED, 0);

            throw new ScreenerQueryUnavailable('user_daily_limit');
        }

        if ($counted->count() >= max(0, (int) config('ai_screener.daily_limit'))) {
            $this->log($user, $locale, $length, AiScreenerQuery::OUTCOME_LIMITED, 0);

            throw new ScreenerQueryUnavailable('daily_limit_reached');
        }
    }

    /**
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}|null  $usage
     */
    private function log(User $user, string $locale, int $length, string $outcome, int $unsupported, ?array $usage = null, ?int $latency = null): void
    {
        AiScreenerQuery::query()->create([
            'user_id' => $user->id,
            'locale' => $locale,
            'query_length' => min($length, 65535),
            'outcome' => $outcome,
            'unsupported_count' => $unsupported,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'latency_ms' => $latency,
            'model' => mb_substr((string) $this->client->model(), 0, 120) ?: null,
            'schema_version' => (string) config('ai_screener.schema_version'),
            'prompt_version' => (string) config('ai_screener.prompt_version'),
        ]);
    }
}
