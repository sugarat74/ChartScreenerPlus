<?php

namespace App\Models;

use Database\Factories\MarketRegimeSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One daily Market Regime: a rule-based, descriptive label of the market on
 * one session with its components (docs/specs/market-regime.md, Rule 6).
 * Never a forecast or advice; the backend stores no text.
 *
 * Derived data written only by `App\Services\MarketRegime\MarketRegimeComputer`.
 */
#[Fillable([
    'date',
    'regime',
    'score',
    'available',
    'components',
    'rules_version',
])]
class MarketRegimeSnapshot extends Model
{
    /** @use HasFactory<MarketRegimeSnapshotFactory> */
    use HasFactory;

    public const REGIME_FAVOURABLE = 'favourable';

    public const REGIME_NEUTRAL = 'neutral';

    public const REGIME_ADVERSE = 'adverse';

    /** Fewer than the minimum number of `ok` components: never a guessed label. */
    public const REGIME_INSUFFICIENT = 'insufficient';

    /** The component counts in the regime. */
    public const STATUS_OK = 'ok';

    /** A reading exists but is older than allowed; kept for display, not counted. */
    public const STATUS_STALE = 'stale';

    /** Enabled, but no reading on or before the date (or it cannot be computed). */
    public const STATUS_MISSING = 'missing';

    /** The input is switched off (macro disabled or a restricted series off). */
    public const STATUS_DISABLED = 'disabled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'score' => 'integer',
            'available' => 'integer',
            'components' => 'array',
            'rules_version' => 'integer',
        ];
    }
}
