<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One precomputed Candidate row per Instrument (screener-precomputed).
 *
 * Written only by `App\Services\Screener\ScreenerRowBuilder` (`screener:refresh`);
 * read by `CandidateQuery`, which filters, ranks and pages these rows in SQL.
 */
class ScreenerRow extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'date' => 'date',
            'change_percent' => 'float',
            'price_above_sma200' => 'boolean',
            'signal_count' => 'integer',
            'signals' => 'array',
            'patterns' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
