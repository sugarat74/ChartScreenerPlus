<?php

namespace App\Console\Commands;

use App\Services\MarketContext\MarketContextComputer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Compute the Sector Snapshots and the market Breadth Snapshot
 * (docs/specs/market-context-sectors.md). Thin wrapper over
 * {@see MarketContextComputer}; idempotent (upsert).
 *
 * - No option: the latest 5 sessions, so a one-day ETF ingestion failure
 *   heals itself on the next run.
 * - `--date=Y-m-d`: one session (exit 1 when it is not a session).
 * - `--from=Y-m-d`: every session on or after that date (the backfill).
 */
class ComputeMarketContext extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-context:compute
                            {--date= : Compute one session (Y-m-d)}
                            {--from= : Backfill every session from this date (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute the daily sector and market breadth snapshots (Market Context)';

    /**
     * Execute the console command.
     */
    public function handle(MarketContextComputer $computer): int
    {
        $date = $this->dateOption('date');
        $from = $this->dateOption('from');

        if ($date === false || $from === false) {
            $this->error('--date and --from must be dates in Y-m-d format.');

            return self::FAILURE;
        }

        if ($date !== null && $from !== null) {
            $this->error('Use either --date or --from, not both.');

            return self::FAILURE;
        }

        try {
            if ($computer->marketUniverse() === null) {
                throw new RuntimeException('No universe found for ['.config('market_context.universe').']. Run the MarketContextUniverseSeeder.');
            }

            $dates = $computer->sessionDates($from, $date);

            if ($date !== null && $dates === []) {
                $this->error("{$date} is not a market session of the main universe.");

                return self::FAILURE;
            }

            if ($dates === []) {
                $this->info('No sessions to compute.');

                return self::SUCCESS;
            }

            $result = $computer->compute($dates);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Market context computed for %d session(s) (%s to %s): %d sector row(s), %d breadth row(s).',
            count($dates),
            $dates[0],
            $dates[array_key_last($dates)],
            $result['sectors'],
            $result['breadth'],
        ));

        foreach ($result['skipped'] as $skipped) {
            $this->warn("  {$skipped}: the benchmark has no bar; sector rows skipped.");
        }

        return self::SUCCESS;
    }

    /**
     * The option as `Y-m-d`, `null` when absent, `false` when malformed.
     */
    private function dateOption(string $name): string|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            return false;
        }

        return $parsed !== null && $parsed->format('Y-m-d') === $value ? $value : false;
    }
}
