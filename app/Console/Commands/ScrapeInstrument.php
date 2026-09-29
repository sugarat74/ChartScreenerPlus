<?php

namespace App\Console\Commands;

use App\Exceptions\EmptyIngestionResponseException;
use App\Models\Instrument;
use App\Services\Ingestion\InstrumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Ingest one instrument's EOD bars: the engine fetches and parses them, and
 * the shared InstrumentIngestor persists them into `daily_bars` (Laravel owns
 * the database).
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
    public function handle(InstrumentIngestor $ingestor): int
    {
        $ticker = strtoupper(trim((string) $this->argument('ticker')));

        $instrument = Instrument::query()->where('ticker', $ticker)->first();

        if ($instrument === null) {
            $this->error("No instrument found for [{$ticker}].");

            return self::FAILURE;
        }

        try {
            $result = $ingestor->ingestDetailed($instrument);
        } catch (RequestException|ConnectionException $exception) {
            $this->error("Engine failed for [{$ticker}]: {$exception->getMessage()}");

            return self::FAILURE;
        } catch (EmptyIngestionResponseException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Stored {$result['stored']} bars for {$ticker}; skipped {$result['skipped']} malformed rows."
        );

        return self::SUCCESS;
    }
}
