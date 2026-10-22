<?php

namespace App\Models;

use Database\Factories\MarketSectorSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Sector Snapshot: a sector ETF's returns, relative strength vs SPY, trend
 * and sector Breadth on one session (docs/specs/market-context-sectors.md).
 *
 * Derived data written only by `App\Services\MarketContext\MarketContextComputer`.
 */
#[Fillable([
    'date',
    'instrument_id',
    'sector',
    'ret_5',
    'ret_20',
    'ret_60',
    'rs_20',
    'rs_60',
    'rs_rank',
    'trend',
    'breadth_200',
])]
class MarketSectorSnapshot extends Model
{
    /** @use HasFactory<MarketSectorSnapshotFactory> */
    use HasFactory;

    public const TREND_UP = 'uptrend';

    public const TREND_DOWN = 'downtrend';

    public const TREND_MIXED = 'mixed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ret_5' => 'decimal:4',
            'ret_20' => 'decimal:4',
            'ret_60' => 'decimal:4',
            'rs_20' => 'decimal:4',
            'rs_60' => 'decimal:4',
            'rs_rank' => 'integer',
            'breadth_200' => 'decimal:4',
        ];
    }

    /**
     * The sector ETF this snapshot belongs to.
     *
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
