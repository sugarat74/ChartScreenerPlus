<?php

namespace App\Console\Commands;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
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
 * Compute indicator snapshots for stored daily bars.
 *
 * The engine computes the indicators from the bars it is sent; Laravel is the
 * only database owner and persists one snapshot per `(instrument, date)` in a
 * transaction. The upsert is keyed on the unique `(instrument_id, date)` pair
 * (with the date normalized to the start of the day), so re-running is
 * idempotent and nothing is written when the engine fails.
 */
class ComputeIndicators extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indicators:compute
                            {--ticker= : Compute indicators for one instrument ticker}
                            {--universe= : Universe slug to compute indicators for (defaults to sp500)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute indicator snapshots for stored daily bars via the engine';

    /**
     * The snapshot columns the engine returns, in payload order.
     *
     * @var list<string>
     */
    private const SNAPSHOT_COLUMNS = [
        'sma20',
        'sma50',
        'sma200',
        'ema21',
        'ema55',
        'rsi14',
        'adx',
        'macd',
        'macd_signal',
        'macd_hist',
        'bb_upper',
        'bb_middle',
        'bb_lower',
        'rvol',
    ];

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
                $written += $this->computeForInstrument($client, $instrument);
                $processed++;
            } catch (RequestException|ConnectionException $exception) {
                // One engine failure must not write anything for that instrument;
                // other instruments in the same run are still processed.
                $failed++;
                $this->warn("Engine failed for {$instrument->ticker}: {$exception->getMessage()}");
            }
        }

        $this->info("Processed {$processed} instrument(s); wrote {$written} snapshot(s).");

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
     * Send one instrument's bars to the engine and upsert the snapshots.
     *
     * @return int the number of snapshots written
     *
     * @throws RequestException when the engine responds with an error status.
     * @throws ConnectionException when the engine is unreachable.
     */
    private function computeForInstrument(EngineClient $client, Instrument $instrument): int
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

        $snapshots = $client->computeIndicators($payload);

        $written = DB::transaction(function () use ($instrument, $snapshots): int {
            $count = 0;

            foreach ($snapshots as $snapshot) {
                $normalized = $this->normalizeSnapshot($snapshot);

                if ($normalized === null) {
                    continue;
                }

                // The `date` cast stores/compares a full datetime, so key the
                // upsert on the same date object; a raw `Y-m-d` string would
                // miss the stored row and violate the unique index on re-run.
                $date = Carbon::parse($normalized['date'])->startOfDay();

                IndicatorSnapshot::query()->updateOrCreate(
                    ['instrument_id' => $instrument->id, 'date' => $date],
                    ['date' => $date] + $normalized['values'],
                );

                $count++;
            }

            return $count;
        });

        $this->info("Computed {$written} snapshot(s) for {$instrument->ticker}.");

        return $written;
    }

    /**
     * Validate one engine snapshot and map it to indicator_snapshots attributes.
     *
     * A missing or invalid value becomes `null` (never a wrong number); an
     * invalid date drops the whole snapshot.
     *
     * @return array{date: string, values: array<string, float|null>}|null
     */
    private function normalizeSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $date = $snapshot['date'] ?? null;

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $values = [];

        foreach (self::SNAPSHOT_COLUMNS as $column) {
            $value = $snapshot[$column] ?? null;
            $values[$column] = is_numeric($value) ? (float) $value : null;
        }

        return ['date' => $date, 'values' => $values];
    }
}
