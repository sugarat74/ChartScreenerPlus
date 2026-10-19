<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One natural-language Screener request (ai-screener-natural-language).
 * Metadata only: the query text is never stored. `ok`, `invalid` and
 * in-flight `pending` rows of the last 24 h count against the daily caps; rows older than
 * `config('ai_screener.log_retention_days')` are mass-pruned daily
 * (`routes/console.php`).
 */
#[Fillable([
    'user_id', 'locale', 'query_length', 'outcome', 'unsupported_count',
    'prompt_tokens', 'completion_tokens', 'latency_ms', 'model',
    'schema_version', 'prompt_version',
])]
class AiScreenerQuery extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const OUTCOME_OK = 'ok';

    public const OUTCOME_CACHED = 'cached';

    public const OUTCOME_INVALID = 'invalid';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public const OUTCOME_LIMITED = 'limited';

    /**
     * A slot reserved under the caps lock before the provider call; replaced
     * by the final outcome once the call ends.
     */
    public const OUTCOME_PENDING = 'pending';

    /** Outcomes that reached (or are reaching) the provider and count against the caps. */
    public const COUNTED_OUTCOMES = [self::OUTCOME_OK, self::OUTCOME_INVALID, self::OUTCOME_PENDING];

    protected function casts(): array
    {
        return [
            'query_length' => 'integer',
            'unsupported_count' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays(max(1, (int) config('ai_screener.log_retention_days'))),
        );
    }
}
