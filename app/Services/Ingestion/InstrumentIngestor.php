<?php

namespace App\Services\Ingestion;

use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\EmptyIngestionResponseException;
use App\Exceptions\PriceScaleMismatchException;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Services\Engine\EngineClient;
use App\Support\ListingMarket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared EOD ingestion for one instrument.
 *
 * The engine fetches and parses the source data; this service owns the
 * database write (Laravel is the only database owner). The upsert is keyed on
 * the unique `(instrument_id, date)` pair, so re-running is idempotent, and
 * nothing is written when the engine returns no usable bars.
 */
class InstrumentIngestor
{
    /**
     * Markets whose quoting unit can flip at the source (London pence vs
     * pounds). Scoped like the engine guard so other markets keep their
     * existing handling of split-adjusted history.
     */
    private const SCALE_GUARDED_MARKETS = [ListingMarket::XLON];

    /** Same threshold as the engine's consecutive-close guard. */
    private const SCALE_JUMP_RATIO = 40.0;

    public function __construct(private readonly EngineClient $client) {}

    /**
     * Ingest one instrument and return the number of bars stored.
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     * @throws EmptyIngestionResponseException when the engine returns no bars.
     */
    public function ingest(Instrument $instrument): int
    {
        return $this->ingestDetailed($instrument)['stored'];
    }

    /**
     * Ingest one instrument and return the stored/skipped bar counts.
     *
     * @return array{stored: int, skipped: int}
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     * @throws EmptyIngestionResponseException when the engine returns no bars.
     * @throws CurrencyMismatchException when the engine reports another currency (nothing is stored).
     * @throws PriceScaleMismatchException when a London series is 40x or more off the stored closes (nothing is stored).
     */
    public function ingestDetailed(Instrument $instrument): array
    {
        $series = $this->client->eodSeries($instrument->ticker, $instrument->listingMarket());
        $bars = $series['bars'];

        // A wrong symbol mapping (a line in another currency) must never reach
        // the database. A source that reports no currency (Stooq) is accepted.
        $expectedCurrency = strtoupper((string) ($instrument->currency ?? 'USD'));
        if ($series['currency'] !== null && $series['currency'] !== $expectedCurrency) {
            throw CurrencyMismatchException::forTicker($instrument->ticker, $expectedCurrency, $series['currency']);
        }

        if ($bars === []) {
            throw EmptyIngestionResponseException::forTicker($instrument->ticker);
        }

        if (in_array($instrument->listingMarket(), self::SCALE_GUARDED_MARKETS, true)) {
            $this->guardStoredScale($instrument, $bars);
        }

        /** @var array{stored: int, skipped: int} $result */
        $result = DB::transaction(function () use ($instrument, $bars): array {
            $stored = 0;
            $skipped = 0;

            foreach ($bars as $bar) {
                $attributes = $this->normalizeBar($bar);

                if ($attributes === null) {
                    $skipped++;

                    continue;
                }

                // The `date` cast stores/compares a full datetime, so key the
                // upsert on the same date object; a raw `Y-m-d` string would
                // miss the stored row and violate the unique index on re-run.
                $barDate = Carbon::parse($attributes['date'])->startOfDay();

                DailyBar::query()->updateOrCreate(
                    ['instrument_id' => $instrument->id, 'date' => $barDate],
                    [
                        'date' => $barDate,
                        'open' => $attributes['open'],
                        'high' => $attributes['high'],
                        'low' => $attributes['low'],
                        'close' => $attributes['close'],
                        'volume' => $attributes['volume'],
                    ],
                );

                $stored++;
            }

            return ['stored' => $stored, 'skipped' => $skipped];
        });

        return $result;
    }

    /**
     * Compare the incoming series with the latest stored close: the engine
     * guard only sees one response, so a source that relabels a whole history
     * from pence to pounds (or back) would otherwise overwrite stored pence
     * with values 100x off. The anchor is the incoming bar on the latest
     * stored date or, if that date is absent, the first one after it.
     *
     * @param  list<mixed>  $bars
     *
     * @throws PriceScaleMismatchException when the ratio is 40 or more.
     */
    private function guardStoredScale(Instrument $instrument, array $bars): void
    {
        $stored = DailyBar::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('date')
            ->first(['date', 'close']);

        if ($stored === null || (float) $stored->close <= 0) {
            return;
        }

        $storedDate = Carbon::parse($stored->date)->toDateString();
        $anchor = null;

        foreach ($bars as $bar) {
            $attributes = $this->normalizeBar($bar);

            if ($attributes === null || $attributes['date'] < $storedDate || $attributes['close'] <= 0) {
                continue;
            }

            if ($anchor === null || $attributes['date'] < $anchor['date']) {
                $anchor = $attributes;
            }
        }

        if ($anchor === null) {
            return;
        }

        $storedClose = (float) $stored->close;
        $ratio = max($storedClose, $anchor['close']) / min($storedClose, $anchor['close']);

        if ($ratio >= self::SCALE_JUMP_RATIO) {
            throw PriceScaleMismatchException::forTicker($instrument->ticker, $anchor['date'], $storedClose, $anchor['close']);
        }
    }

    /**
     * Validate one engine bar and map it to daily_bars attributes.
     *
     * @return array{date: string, open: float, high: float, low: float, close: float, volume: int}|null
     */
    private function normalizeBar(mixed $bar): ?array
    {
        if (! is_array($bar)) {
            return null;
        }

        $date = $bar['date'] ?? null;

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        foreach (['open', 'high', 'low', 'close'] as $key) {
            if (! isset($bar[$key]) || ! is_numeric($bar[$key])) {
                return null;
            }
        }

        if (! isset($bar['volume']) || ! is_numeric($bar['volume']) || (float) $bar['volume'] < 0) {
            return null;
        }

        return [
            'date' => $date,
            'open' => (float) $bar['open'],
            'high' => (float) $bar['high'],
            'low' => (float) $bar['low'],
            'close' => (float) $bar['close'],
            'volume' => (int) $bar['volume'],
        ];
    }
}
