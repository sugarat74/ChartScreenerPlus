<?php

namespace App\Models;

use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ticker', 'company', 'sector', 'exchange', 'active'])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The universes this instrument belongs to.
     *
     * @return BelongsToMany<Universe, $this>
     */
    public function universes(): BelongsToMany
    {
        return $this->belongsToMany(Universe::class);
    }

    /**
     * The end-of-day bars recorded for this instrument.
     *
     * @return HasMany<DailyBar, $this>
     */
    public function dailyBars(): HasMany
    {
        return $this->hasMany(DailyBar::class);
    }

    /**
     * The indicator snapshots computed for this instrument.
     *
     * @return HasMany<IndicatorSnapshot, $this>
     */
    public function indicatorSnapshots(): HasMany
    {
        return $this->hasMany(IndicatorSnapshot::class);
    }

    /**
     * The signals detected for this instrument.
     *
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }
}
