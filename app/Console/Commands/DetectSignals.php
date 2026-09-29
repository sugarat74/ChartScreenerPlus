<?php

namespace App\Console\Commands;

use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use App\Services\Engine\EngineClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Detect the deterministic signals that hold on each instrument's as-of bar.
 *
 * `signals` holds the *current active* set, so this is a replace per instrument:
 * the engine response is fetched first (an engine failure leaves the previous
 * set intact), then all rows for the instrument are deleted and the freshly
 * detected set inserted in one transaction. Re-running the same bars is
 * idempotent, and a signal that no longer holds disappears.
 */
class DetectSignals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'signals:detect
                            {--ticker= : Detect signals for one instrument ticker}
                            {--universe= : Universe slug to detect signals for (defaults to sp500)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and replace the current signals for stored daily bars via the engine';

    /**
     * Execute the console command.
     */
    public function handle(EngineClient $client): int
    {
        try {
            $instruments = $this->targetInstruments();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($instruments->isEmpty()) {
            $this->info('No instruments with stored daily bars to process.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $written = 0;

        foreach ($instruments as $instrument) {
            try {
                $written += $this->detectForInstrument($client, $instrument);
                $processed++;
            } catch (RequestException|ConnectionException $exception) {
                // One engine failure must leave that instrument's stored set
                // untouched; other instruments in the same run still run.
                $failed++;
                $this->warn("Engine failed for {$instrument->ticker}: {$exception->getMessage()}");
            }
        }

        $this->info("Processed {$processed} instrument(s); stored {$written} signal(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve the instruments to process: one `--ticker`, or every instrument
     * of the `--universe` that actually has stored daily bars.
     *
     * @return Collection<int, Instrument>
     */
    private function targetInstruments(): Collection
    {
        $ticker = strtoupper(trim((string) $this->option('ticker')));

        if ($ticker !== '') {
            $instrument = Instrument::query()->where('ticker', $ticker)->first();

            if ($instrument === null) {
                throw new RuntimeException("No instrument found for [{$ticker}].");
            }

            return Instrument::query()
                ->whereKey($instrument->id)
                ->whereHas('dailyBars')
                ->get();
        }

        $slug = (string) ($this->option('universe') ?: 'sp500');
        $universe = Universe::query()->where('slug', $slug)->first();

        if ($universe === null) {
            throw new RuntimeException("No universe found for [{$slug}].");
        }

        return $universe->instruments()
            ->whereHas('dailyBars')
            ->orderBy('ticker')
            ->get();
    }

    /**
     * Send one instrument's bars to the engine and replace its signal set.
     *
     * The engine call is made before the delete so a failure cannot leave the
     * instrument with no signals; the delete and inserts share one transaction.
     *
     * @return int the number of signals stored
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    private function detectForInstrument(EngineClient $client, Instrument $instrument): int
    {
        $bars = $instrument->dailyBars()->orderBy('date')->get();

        $payload = $bars->map(fn (DailyBar $bar): array => [
            'date' => $bar->date->toDateString(),
            'open' => (float) $bar->open,
            'high' => (float) $bar->high,
            'low' => (float) $bar->low,
            'close' => (float) $bar->close,
            'volume' => (int) $bar->volume,
        ])->all();

        $signals = $client->detectSignals($payload);

        $normalized = [];

        foreach ($signals as $signal) {
            $entry = $this->normalizeSignal($signal);

            if ($entry === null) {
                continue;
            }

            // The engine returns each type at most once; keying by type makes
            // the in-memory set match the unique `(instrument, date, type)`
            // invariant so a malformed payload can never violate it.
            $normalized[$entry['type']] = $entry;
        }

        $written = DB::transaction(function () use ($instrument, $normalized): int {
            Signal::query()->where('instrument_id', $instrument->id)->delete();

            $count = 0;

            foreach ($normalized as $entry) {
                // The model's `date` cast stores/compares a full datetime, so
                // key it on a date object normalized to the start of the day.
                Signal::query()->create([
                    'instrument_id' => $instrument->id,
                    'date' => Carbon::parse($entry['date'])->startOfDay(),
                    'type' => $entry['type'],
                    'metadata' => $entry['metadata'],
                ]);

                $count++;
            }

            return $count;
        });

        $this->info("Stored {$written} signal(s) for {$instrument->ticker}.");

        return $written;
    }

    /**
     * Validate one engine signal and map it to `signals` attributes.
     *
     * An invalid date or type drops the whole signal; metadata keeps only
     * numeric values, so a malformed payload never stores junk.
     *
     * @return array{date: string, type: string, metadata: array<string, float>}|null
     */
    private function normalizeSignal(mixed $signal): ?array
    {
        if (! is_array($signal)) {
            return null;
        }

        $date = $signal['date'] ?? null;
        $type = $signal['type'] ?? null;

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $metadata = [];
        $rawMetadata = $signal['metadata'] ?? [];

        if (is_array($rawMetadata)) {
            foreach ($rawMetadata as $key => $value) {
                if (is_string($key) && is_numeric($value)) {
                    $metadata[$key] = (float) $value;
                }
            }
        }

        return ['date' => $date, 'type' => $type, 'metadata' => $metadata];
    }
}
