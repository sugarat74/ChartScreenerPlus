<?php

namespace App\Models;

use Database\Factories\MarketBreadthSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Breadth Snapshot: the share of the main Universe above its moving
 * averages, new highs/lows, advancers/decliners and SPY's trend on one session
 * (docs/specs/market-context-sectors.md).
 *
 * Derived data written only by `App\Services\MarketContext\MarketContextComputer`.
 */
#[Fillable([
    'universe_id',
    'date',
    'members',
    'pct_above_sma50',
    'pct_above_sma200',
    'new_highs',
    'new_lows',
    'advancers',
    'decliners',
    'spy_trend',
])]
class MarketBreadthSnapshot extends Model
{
    /** @use HasFactory<MarketBreadthSnapshotFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'members' => 'integer',
            'pct_above_sma50' => 'decimal:4',
            'pct_above_sma200' => 'decimal:4',
            'new_highs' => 'integer',
            'new_lows' => 'integer',
            'advancers' => 'integer',
            'decliners' => 'integer',
        ];
    }

    /**
     * The Universe this Breadth describes.
     *
     * @return BelongsTo<Universe, $this>
     */
    public function universe(): BelongsTo
    {
        return $this->belongsTo(Universe::class);
    }
}
