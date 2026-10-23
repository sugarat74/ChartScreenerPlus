<?php

namespace App\Console\Commands;

use App\Services\MarketRegime\MarketRegimeComputer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Compute the daily Market Regime (docs/specs/market-regime.md). Thin wrapper
 * over {@see MarketRegimeComputer}; idempotent (upsert). Sessions before the
 * main Universe's first Breadth Snapshot are never computed.
 *
 * - No option: the latest 5 sessions, so late macro publications and healed
 *   snapshots are folded in; on an empty table, every session from the first
 *   snapshot (the self-seed).
 * - `--date=Y-m-d`: one session (exit 1 when it is not a session or precedes
 *   the first snapshot).
 * - `--from=Y-m-d`: every session on or after that date (the backfill).
 */
class ComputeMarketRegime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-regime:compute
                            {--date= : Compute one session (Y-m-d)}
                            {--from= : Backfill every session from this date (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute the daily rule-based Market Regime and its components';

    /**
     * Execute the console command.
     */
    public function handle(MarketRegimeComputer $computer): int
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
            $first = $computer->firstSnapshotDate();

            if ($first === null) {
                $this->info('No market snapshots yet; nothing to compute.');

                return self::SUCCESS;
            }

            if ($date !== null && $date < $first) {
                $this->error("{$date} is before the first market snapshot ({$first}).");

                return self::FAILURE;
            }

            if ($from !== null && $from < $first) {
                $this->info("Starting at the first market snapshot ({$first}).");
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

        $latest = $result['latest'];

        $this->info(sprintf(
            'Market regime computed for %d session(s) (%s to %s); latest %s: %s (score %s, %d available).',
            count($dates),
            $dates[0],
            $dates[array_key_last($dates)],
            $latest['date'],
            $latest['regime'],
            $latest['score'] ?? 'n/a',
            $latest['available'],
        ));

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
