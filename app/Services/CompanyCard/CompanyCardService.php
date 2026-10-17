<?php

namespace App\Services\CompanyCard;

use App\Models\AiCompanyCard;
use App\Models\AiCompanyCardGeneration;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Ai\StructuredCompletion;
use App\Services\Fundamentals\CompanyFundamentalsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read and generate shared AI Company Cards (docs/specs/ai-company-card.md).
 *
 * `current()` only reads the database. `generate()` reuses a fresh card
 * before anything else, then checks configuration, takes a per-key lock,
 * re-reads (a concurrent request may have produced the card), enforces the
 * daily caps, calls the provider once and stores the card only when it passes
 * `CompanyCardSchema::validate`. Every generation attempt (including a
 * json_schema -> json_object fallback, which is one attempt of up to two HTTP
 * requests) writes one log row without prompt or response text.
 *
 * A card remembers the SEC filing (accession) its outlook was grounded on;
 * when Company Fundamentals move to a newer filing the card is stale.
 */
class CompanyCardService
{
    private readonly CompanyFundamentalsService $fundamentals;

    public function __construct(
        private readonly OpenAiCompatibleClient $client,
        private readonly CompanyCardContextBuilder $contextBuilder,
        ?CompanyFundamentalsService $fundamentals = null,
    ) {
        $this->fundamentals = $fundamentals ?? app(CompanyFundamentalsService::class);
    }

    /** Generation is enabled and the provider is fully configured. */
    public function available(): bool
    {
        return (bool) config('ai_card.enabled') && $this->client->isConfigured();
    }

    /** @return array<string, mixed>|null */
    public function current(Instrument $instrument, string $locale): ?array
    {
        $card = $this->find($instrument, $locale);

        return $card === null ? null : $this->payload($card, $instrument);
    }

    /**
     * @return array{card: array<string, mixed>, reused: bool}
     *
     * @throws CompanyCardUnavailable
     */
    public function generate(Instrument $instrument, string $locale, User $user): array
    {
        $existing = $this->find($instrument, $locale);
        if ($existing !== null && $this->isFresh($existing, $instrument)) {
            return ['card' => $this->payload($existing, $instrument), 'reused' => true];
        }

        if (! $this->available()) {
            throw new CompanyCardUnavailable('provider_not_configured');
        }

        $lockKey = 'ai-card:'.hash('sha256', implode('|', [$instrument->id, $locale, config('ai_card.schema_version')]));

        return $this->withLock($lockKey, function () use ($instrument, $locale, $user): array {
            $existing = $this->find($instrument, $locale);
            if ($existing !== null && $this->isFresh($existing, $instrument)) {
                return ['card' => $this->payload($existing, $instrument), 'reused' => true];
            }

            $this->enforceCaps($user);

            return ['card' => $this->payload($this->produce($instrument, $locale, $user), $instrument), 'reused' => false];
        });
    }

    /**
     * Run `$callback` under the per-key lock. A request that cannot get the
     * lock within the provider timeout (+10 s) is `busy`.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withLock(string $key, callable $callback): mixed
    {
        $timeout = max(1, (int) config('ai_card.timeout_seconds'));
        try {
            return Cache::lock($key, $timeout + 15)->block($this->lockWaitSeconds(), $callback);
        } catch (LockTimeoutException) {
            throw new CompanyCardUnavailable('busy');
        }
    }

    /** How long a request waits for a concurrent generation of the same key. */
    protected function lockWaitSeconds(): int
    {
        return max(1, (int) config('ai_card.timeout_seconds')) + 10;
    }

    private function enforceCaps(User $user): void
    {
        $since = now('UTC')->startOfDay();
        $mine = AiCompanyCardGeneration::query()->where('user_id', $user->id)->where('created_at', '>=', $since)->count();
        if ($mine >= max(0, (int) config('ai_card.user_daily_limit'))) {
            throw new CompanyCardUnavailable('user_daily_limit');
        }
        $all = AiCompanyCardGeneration::query()->where('created_at', '>=', $since)->count();
        if ($all >= max(0, (int) config('ai_card.daily_limit'))) {
            throw new CompanyCardUnavailable('daily_limit_reached');
        }
    }

    private function produce(Instrument $instrument, string $locale, User $user): AiCompanyCard
    {
        $built = $this->contextBuilder->build($instrument);
        $messages = CompanyCardPrompt::messages($built['context'], $locale, now('UTC')->format('Y-m-d'));
        $model = (string) $this->client->model();

        try {
            $completion = $this->client->structured($messages, CompanyCardSchema::NAME, CompanyCardSchema::schema());
        } catch (AiProviderException $exception) {
            $failed = $exception->reason === 'invalid_provider_response'
                ? AiCompanyCardGeneration::STATUS_INVALID_RESPONSE
                : AiCompanyCardGeneration::STATUS_PROVIDER_ERROR;
            $this->log($instrument, $locale, $user, $model, $exception->responseFormat, $failed, $exception->reason, $exception->usage, $exception->latencyMs);

            throw new CompanyCardUnavailable($exception->reason);
        }

        try {
            $content = CompanyCardSchema::validate($completion->data, $instrument->ticker);
        } catch (InvalidCompanyCard $exception) {
            Log::warning('AI company card rejected by schema validation', ['rule' => $exception->getMessage()]);
            $this->logCompletion($instrument, $locale, $user, $model, $completion, AiCompanyCardGeneration::STATUS_INVALID_RESPONSE, 'invalid_provider_response');

            throw new CompanyCardUnavailable('invalid_provider_response');
        }

        return DB::transaction(function () use ($instrument, $locale, $user, $model, $completion, $content, $built): AiCompanyCard {
            $card = AiCompanyCard::query()->updateOrCreate(
                [
                    'instrument_id' => $instrument->id,
                    'locale' => $locale,
                    'schema_version' => (string) config('ai_card.schema_version'),
                ],
                [
                    'prompt_version' => (string) config('ai_card.prompt_version'),
                    'model' => $model,
                    'context_mode' => $built['mode'],
                    'context_as_of_date' => $built['as_of_date'],
                    'fundamentals_accession' => $built['fundamentals_accession'],
                    'fundamentals_period_end' => $built['fundamentals_period_end'],
                    'content' => $content,
                    'generated_at' => now(),
                ],
            );
            $this->logCompletion($instrument, $locale, $user, $model, $completion, AiCompanyCardGeneration::STATUS_SUCCEEDED, null);

            return $card;
        });
    }

    private function logCompletion(Instrument $instrument, string $locale, User $user, string $model, StructuredCompletion $completion, string $status, ?string $error): void
    {
        $this->log($instrument, $locale, $user, $model, $completion->responseFormat, $status, $error, $completion->usage, $completion->latencyMs);
    }

    /**
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}  $usage
     */
    private function log(Instrument $instrument, string $locale, User $user, string $model, string $format, string $status, ?string $error, array $usage, ?int $latency): void
    {
        AiCompanyCardGeneration::query()->create([
            'instrument_id' => $instrument->id,
            'user_id' => $user->id,
            'locale' => $locale,
            'model' => mb_substr($model, 0, 120),
            'schema_version' => (string) config('ai_card.schema_version'),
            'prompt_version' => (string) config('ai_card.prompt_version'),
            'response_format' => $format,
            'status' => $status,
            'error_code' => $error,
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens' => $usage['total_tokens'],
            'latency_ms' => $latency,
        ]);
    }

    private function find(Instrument $instrument, string $locale): ?AiCompanyCard
    {
        return AiCompanyCard::query()
            ->where('instrument_id', $instrument->id)
            ->where('locale', $locale)
            ->where('schema_version', (string) config('ai_card.schema_version'))
            ->first();
    }

    private function isFresh(AiCompanyCard $card, Instrument $instrument): bool
    {
        $days = max(0, (int) config('ai_card.freshness_days'));

        return $card->generated_at !== null
            && $card->generated_at->greaterThanOrEqualTo(now()->subDays($days))
            && $card->model === $this->client->model()
            && $card->prompt_version === (string) config('ai_card.prompt_version')
            // A newer 10-K/10-Q in Company Fundamentals makes the card stale
            // (both null counts as equal).
            && $card->fundamentals_accession === $this->fundamentals->accession($instrument);
    }

    /** @return array<string, mixed> */
    private function payload(AiCompanyCard $card, Instrument $instrument): array
    {
        return [
            'ticker' => $instrument->ticker,
            'locale' => $card->locale,
            'schema_version' => $card->schema_version,
            'model' => $card->model,
            'generated_at' => $card->generated_at->copy()->utc()->toIso8601ZuluString(),
            'fresh' => $this->isFresh($card, $instrument),
            'context_mode' => $card->context_mode,
            'context_as_of_date' => $card->context_as_of_date?->format('Y-m-d'),
            'fundamentals_period_end' => $card->fundamentals_period_end?->format('Y-m-d'),
            'content' => $card->content,
        ];
    }
}
