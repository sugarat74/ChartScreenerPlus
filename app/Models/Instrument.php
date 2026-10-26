<?php

namespace App\Models;

use App\Support\ListingMarket;
use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['ticker', 'company', 'sector', 'industry', 'country', 'exchange', 'active', 'kind', 'market', 'currency'])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    /**
     * A listed company: the only kind the stock features (Screener rows,
     * Watchlist, alerts, Company Card, fundamentals, forecast) handle.
     */
    public const KIND_STOCK = 'stock';

    /**
     * An exchange-traded fund of the Market Context universe (SPY and the
     * sector ETFs); it has bars and indicators but never reaches stock features.
     */
    public const KIND_ETF = 'etf';

    /**
     * Only stock Instruments.
     *
     * @param  Builder<Instrument>  $query
     */
    public function scopeStocks(Builder $query): void
    {
        $query->where($query->qualifyColumn('kind'), self::KIND_STOCK);
    }

    /**
     * Whether this Instrument is an ETF. A model built in memory without a
     * `kind` is a stock, like the column default.
     */
    public function isEtf(): bool
    {
        return ($this->kind ?? self::KIND_STOCK) === self::KIND_ETF;
    }

    /**
     * The Listing Market of this Instrument. A model built in memory without
     * one is a US listing, like the column default.
     */
    public function listingMarket(): string
    {
        return $this->market ?? ListingMarket::US;
    }

    /**
     * Whether the US-only stock features (AI Company Card, SEC fundamentals,
     * forecast) apply: a US-listed stock.
     */
    public function supportsUsOnlyFeatures(): bool
    {
        return ! $this->isEtf() && $this->listingMarket() === ListingMarket::US;
    }

    /**
     * Which feature panels exist for this Instrument, so the SPA does not
     * hardcode market rules.
     *
     * @return array{company_card: bool, fundamentals: bool, forecast: bool}
     */
    public function availability(): array
    {
        $usOnly = $this->supportsUsOnlyFeatures();

        return ['company_card' => $usOnly, 'fundamentals' => $usOnly, 'forecast' => $usOnly];
    }

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
     * The most recent end-of-day bar for this instrument.
     *
     * `daily_bars` is unique per `(instrument_id, date)`, so `latestOfMany`
     * always resolves exactly one row and the screener can eager load it
     * without an N+1 query.
     *
     * @return HasOne<DailyBar, $this>
     */
    public function latestBar(): HasOne
    {
        return $this->hasOne(DailyBar::class)->latestOfMany('date');
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
     * The most recent indicator snapshot for this instrument.
     *
     * `indicator_snapshots` is unique per `(instrument_id, date)`, so
     * `latestOfMany` always resolves exactly one row and the screener can
     * eager load it without an N+1 query.
     *
     * @return HasOne<IndicatorSnapshot, $this>
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(IndicatorSnapshot::class)->latestOfMany('date');
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

    /**
     * The chartist patterns currently active on this instrument.
     *
     * @return HasMany<ChartPattern, $this>
     */
    public function chartPatterns(): HasMany
    {
        return $this->hasMany(ChartPattern::class);
    }

    /**
     * Company Fundamentals computed from the company's SEC filings.
     *
     * @return HasOne<CompanyFundamental, $this>
     */
    public function fundamentals(): HasOne
    {
        return $this->hasOne(CompanyFundamental::class);
    }
}
