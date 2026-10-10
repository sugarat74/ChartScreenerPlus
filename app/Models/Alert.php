<?php

namespace App\Models;

use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Registered User's Alert rule (docs/specs/alerts-engine.md).
 *
 * `user_id` is deliberately absent from `#[Fillable]` (the same structural
 * ownership guard as `SavedScreener`): rows are created through
 * `$request->user()->alerts()->create([...])`, so a client-supplied `user_id`
 * can never be written. Evaluation state (`last_state`,
 * `last_evaluated_as_of`) is written only by the evaluator.
 */
#[Fillable(['kind', 'saved_screener_id', 'signal_types', 'active'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** New Instruments entering a Saved Screener's Candidate list. */
    public const KIND_SCREENER = 'screener_new_candidates';

    /** Chosen Signal types appearing on the user's Watchlist Instruments. */
    public const KIND_WATCHLIST = 'watchlist_signal';

    public const KINDS = [self::KIND_SCREENER, self::KIND_WATCHLIST];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signal_types' => 'array',
            'active' => 'boolean',
            'last_evaluated_as_of' => 'date',
            'last_state' => 'array',
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
     * @return BelongsTo<SavedScreener, $this>
     */
    public function savedScreener(): BelongsTo
    {
        return $this->belongsTo(SavedScreener::class);
    }
}
