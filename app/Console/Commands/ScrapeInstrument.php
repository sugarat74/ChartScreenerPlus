<?php

namespace App\Console\Commands;

use App\Models\DailyBar;
use App\Models\Instrument;
use App\Services\Engine\EngineClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingest one instrument's EOD bars: the engine fetches and parses them, and
 * this command persists them into `daily_bars` (Laravel owns the database).
 *
 * The upsert is keyed on the unique `(instrument_id, date)` pair, so re-running
 * is idempotent. Nothing is written when the engine fails.
 */
class ScrapeInstrument extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingestion:scrape {ticker : Instrument ticker to ingest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch one instrument\'s EOD bars from the engine and upsert them';

    /**
     * Execute the console command.
     */
    public function handle(EngineClient $client): int
    {
        $ticker = strtoupper(trim((string) $this->argument('ticker')));

        $instrument = Instrument::query()->where('ticker', $ticker)->first();

        if ($instrument === null) {
            $this->error("No instrument found for [{$ticker}].");

            return self::FAILURE;
        }

        try {
            $bars = $client->eodBars($ticker);
        } catch (RequestException|ConnectionException $exception) {
            $this->error("Engine failed for [{$ticker}]: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($bars === []) {
            $this->error("Engine returned no EOD bars for [{$ticker}].");

            return self::FAILURE;
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

        $this->info(
            "Stored {$result['stored']} bars for {$ticker}; skipped {$result['skipped']} malformed rows."
        );

        return self::SUCCESS;
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
