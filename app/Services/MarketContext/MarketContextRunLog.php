<?php

namespace App\Services\MarketContext;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The last run of each Market Context command (docs/specs/market-context-ui.md,
 * C4), shown in the Admin tab. One record per command in the cache store
 * (`market-context:run:{command}`), forever; best effort: a cache failure is
 * reported and never fails the command that records it.
 *
 * Every command records its own run whoever starts it (pipeline, schedule,
 * Admin job or console) through {@see \App\Console\Concerns\RecordsMarketContextRun}.
 * `finished()` also renews the Market Context cache version.
 */
final class MarketContextRunLog
{
    public const COMMANDS = ['market-context:compute', 'macro:refresh', 'market-regime:compute'];

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Shown for a `queued`/`running` record older than `run_stale_after_minutes`; never stored. */
    public const INTERRUPTED = 'interrupted';

    /**
     * The stored record of `$command` with `interrupted` applied, or null.
     *
     * @return array{status: string, queued_at: ?string, started_at: ?string, finished_at: ?string, exit_code: ?int}|null
     */
    public static function get(string $command): ?array
    {
        $record = self::stored($command);

        if ($record === null) {
            return null;
        }

        if (self::interrupted($record)) {
            $record['status'] = self::INTERRUPTED;
        }

        return $record;
    }

    /** `queued` or `running`, and not interrupted. */
    public static function isActive(string $command): bool
    {
        $record = self::get($command);

        return $record !== null && in_array($record['status'], [self::QUEUED, self::RUNNING], true);
    }

    /** Raw stored status (`interrupted` not applied), or null. */
    public static function storedStatus(string $command): ?string
    {
        return self::stored($command)['status'] ?? null;
    }

    /** @return array{status: string, queued_at: ?string, started_at: ?string, finished_at: ?string, exit_code: ?int} */
    public static function queued(string $command): array
    {
        $record = [
            'status' => self::QUEUED,
            'queued_at' => self::now(),
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
        ];
        self::store($command, $record);

        return $record;
    }

    public static function started(string $command): void
    {
        $previous = self::stored($command);

        self::store($command, [
            'status' => self::RUNNING,
            // A run the Admin queued keeps its queue time; any other start has none.
            'queued_at' => ($previous['status'] ?? null) === self::QUEUED ? $previous['queued_at'] : null,
            'started_at' => self::now(),
            'finished_at' => null,
            'exit_code' => null,
        ]);
    }

    public static function finished(string $command, int $exitCode): void
    {
        $previous = self::stored($command);

        self::store($command, [
            'status' => $exitCode === 0 ? self::SUCCEEDED : self::FAILED,
            'queued_at' => $previous['queued_at'] ?? null,
            'started_at' => $previous['started_at'] ?? null,
            'finished_at' => self::now(),
            'exit_code' => $exitCode,
        ]);

        try {
            MarketContextCache::bump();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array{status: string, queued_at: ?string, started_at: ?string, finished_at: ?string, exit_code: ?int}|null */
    private static function stored(string $command): ?array
    {
        try {
            $record = Cache::get(self::key($command));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($record) && isset($record['status']) ? $record : null;
    }

    /** @param  array<string, mixed>  $record */
    private static function store(string $command, array $record): void
    {
        try {
            Cache::forever(self::key($command), $record);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param  array<string, mixed>  $record */
    private static function interrupted(array $record): bool
    {
        if (! in_array($record['status'], [self::QUEUED, self::RUNNING], true)) {
            return false;
        }

        $since = $record['started_at'] ?? $record['queued_at'] ?? null;

        if (! is_string($since)) {
            return true;
        }

        $minutes = max(1, (int) config('market_context.run_stale_after_minutes', 30));

        return Carbon::parse($since)->lt(Carbon::now()->subMinutes($minutes));
    }

    private static function key(string $command): string
    {
        return "market-context:run:{$command}";
    }

    private static function now(): string
    {
        return Carbon::now()->utc()->toIso8601ZuluString();
    }
}
