<?php

namespace App\Console\Commands;

use App\Models\ChartPattern;
use App\Models\DailyBar;
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
 * Detect the chartist patterns active on each instrument's as-of bar.
 *
 * `chart_patterns` holds the *current active* set, so this mirrors
 * `signals:detect`: the engine response is fetched first (a failure leaves the
 * previous set intact), then the instrument's rows are deleted and the new set
 * inserted in one transaction. Re-running is idempotent and a pattern that is
 * no longer active disappears. See docs/specs/chart-patterns-detect.md.
 */
class DetectPatterns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'patterns:detect
                            {--ticker= : Detect patterns for one instrument ticker}
                            {--universe= : Universe slug to detect patterns for (defaults to sp500)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and replace the current chartist patterns for stored daily bars via the engine';

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
                $failed++;
                $this->warn("Engine failed for {$instrument->ticker}: {$exception->getMessage()}");
            }
        }

        $this->info("Processed {$processed} instrument(s); stored {$written} pattern(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One `--ticker`, or every instrument of the `--universe` with stored bars.
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
     * Send one instrument's bars to the engine and replace its pattern set.
     *
     * @return int the number of patterns stored
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

        $normalized = [];

        foreach ($client->detectPatterns($payload) as $pattern) {
            $entry = $this->normalizePattern($pattern);

            if ($entry !== null) {
                // At most one row per type keeps the unique
                // `(instrument, as_of_date, type)` invariant.
                $normalized[$entry['type']] = $entry;
            }
        }

        $written = DB::transaction(function () use ($instrument, $normalized): int {
            ChartPattern::query()->where('instrument_id', $instrument->id)->delete();

            foreach ($normalized as $entry) {
                ChartPattern::query()->create([
                    'instrument_id' => $instrument->id,
                    'as_of_date' => Carbon::parse($entry['as_of_date'])->startOfDay(),
                    'type' => $entry['type'],
                    'status' => $entry['status'],
                    'start_date' => Carbon::parse($entry['start_date'])->startOfDay(),
                    'end_date' => Carbon::parse($entry['end_date'])->startOfDay(),
                    'breakout_level' => $entry['breakout_level'],
                    'points' => $entry['points'],
                    'metadata' => $entry['metadata'],
                ]);
            }

            return count($normalized);
        });

        $this->info("Stored {$written} pattern(s) for {$instrument->ticker}.");

        return $written;
    }

    /**
     * Validate one engine pattern; anything malformed drops the whole pattern.
     *
     * Types and statuses outside the fixed vocabulary are rejected (the screener
     * filters on them); metadata keeps only numeric values and points need a
     * date, a numeric price and a role.
     *
     * @return array{type: string, status: string, as_of_date: string, start_date: string, end_date: string, breakout_level: float, points: list<array{date: string, price: float, role: string}>, metadata: array<string, float>}|null
     */
    private function normalizePattern(mixed $pattern): ?array
    {
        if (! is_array($pattern)) {
            return null;
        }

        $type = $pattern['type'] ?? null;
        $status = $pattern['status'] ?? null;

        if (! in_array($type, ChartPattern::TYPES, true) || ! in_array($status, ChartPattern::STATUSES, true)) {
            return null;
        }

        foreach (['as_of_date', 'start_date', 'end_date'] as $key) {
            if (! $this->isDate($pattern[$key] ?? null)) {
                return null;
            }
        }

        $level = $pattern['breakout_level'] ?? null;

        if (! is_numeric($level) || ! is_finite((float) $level)) {
            return null;
        }

        $points = [];

        foreach (is_array($pattern['points'] ?? null) ? $pattern['points'] : [] as $point) {
            if (
                ! is_array($point)
                || ! $this->isDate($point['date'] ?? null)
                || ! is_numeric($point['price'] ?? null)
                || ! is_string($point['role'] ?? null)
                || trim($point['role']) === ''
            ) {
                return null;
            }

            $points[] = ['date' => $point['date'], 'price' => (float) $point['price'], 'role' => $point['role']];
        }

        if ($points === []) {
            return null;
        }

        $metadata = [];

        foreach (is_array($pattern['metadata'] ?? null) ? $pattern['metadata'] : [] as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $metadata[$key] = (float) $value;
            }
        }

        return [
            'type' => $type,
            'status' => $status,
            'as_of_date' => $pattern['as_of_date'],
            'start_date' => $pattern['start_date'],
            'end_date' => $pattern['end_date'],
            'breakout_level' => (float) $level,
            'points' => $points,
            'metadata' => $metadata,
        ];
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
