<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of daily alert email digests (alerts-email-digest): one row per user
 * and as-of date, created before the email is queued so a digest is never
 * sent twice. `sent_at` stays null until the transport accepted the message.
 * Rows are pruned after `alerts.email_digest.ledger_retention_days`.
 */
#[Fillable(['user_id', 'as_of', 'items', 'sent_at'])]
class AlertEmailDigest extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'items' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays(max(1, (int) config('alerts.email_digest.ledger_retention_days'))),
        );
    }
}
