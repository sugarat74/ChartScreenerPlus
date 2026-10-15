<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted provider call for a Company Card. No prompt or response text
 * is stored; rows older than `config('ai_card.log_retention_days')` are
 * mass-pruned daily (`routes/console.php`).
 */
#[Fillable([
    'instrument_id', 'user_id', 'locale', 'model', 'schema_version', 'prompt_version',
    'response_format', 'status', 'error_code', 'prompt_tokens', 'completion_tokens',
    'total_tokens', 'latency_ms',
])]
class AiCompanyCardGeneration extends Model
{
    use MassPrunable;

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    public const STATUS_PROVIDER_ERROR = 'provider_error';

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
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
            now()->subDays(max(1, (int) config('ai_card.log_retention_days'))),
        );
    }
}
