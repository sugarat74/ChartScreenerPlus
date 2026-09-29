<?php

namespace App\Models;

use Database\Factories\IndicatorSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'instrument_id',
    'date',
    'sma20',
    'sma50',
    'sma200',
    'ema21',
    'ema55',
    'rsi14',
    'adx',
    'macd',
    'macd_signal',
    'macd_hist',
    'bb_upper',
    'bb_middle',
    'bb_lower',
    'rvol',
])]
class IndicatorSnapshot extends Model
{
    /** @use HasFactory<IndicatorSnapshotFactory> */
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
            'sma20' => 'decimal:4',
            'sma50' => 'decimal:4',
            'sma200' => 'decimal:4',
            'ema21' => 'decimal:4',
            'ema55' => 'decimal:4',
            'rsi14' => 'decimal:4',
            'adx' => 'decimal:4',
            'macd' => 'decimal:4',
            'macd_signal' => 'decimal:4',
            'macd_hist' => 'decimal:4',
            'bb_upper' => 'decimal:4',
            'bb_middle' => 'decimal:4',
            'bb_lower' => 'decimal:4',
            'rvol' => 'decimal:4',
        ];
    }

    /**
     * The instrument this snapshot belongs to.
     *
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
