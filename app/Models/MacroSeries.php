<?php

namespace App\Models;

use Database\Factories\MacroSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Macro Series of the closed list in config/macro.php and its refresh state
 * (docs/specs/macro-series-fred.md). Written only by
 * `App\Services\Macro\MacroSeriesRefresher`; `last_error` is a code
 * (`unavailable`, `invalid_key`, ...), never a message.
 */
#[Fillable([
    'code',
    'source',
    'category',
    'frequency',
    'title',
    'units',
    'last_observation_date',
    'source_updated_at',
    'last_attempted_at',
    'last_refreshed_at',
    'last_error',
])]
class MacroSeries extends Model
{
    /** @use HasFactory<MacroSeriesFactory> */
    use HasFactory;

    public const SOURCE_FRED = 'fred';

    protected $table = 'macro_series';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_observation_date' => 'date',
            'source_updated_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    /**
     * The stored observations of this series.
     *
     * @return HasMany<MacroObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(MacroObservation::class);
    }
}
