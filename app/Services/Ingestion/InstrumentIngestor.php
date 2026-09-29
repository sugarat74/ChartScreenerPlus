<?php

namespace App\Services\Ingestion;

use App\Exceptions\EmptyIngestionResponseException;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Services\Engine\EngineClient;
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
     */
    public function ingestDetailed(Instrument $instrument): array
    {
        $bars = $this->client->eodBars($instrument->ticker);

        if ($bars === []) {
            throw EmptyIngestionResponseException::forTicker($instrument->ticker);
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
