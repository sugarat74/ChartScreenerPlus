<?php

namespace App\Console\Commands;

use App\Services\Database\InvalidSourceValue;
use App\Services\Database\PostgresValueNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off production cut-over copy from the legacy SQLite file into a freshly
 * migrated PostgreSQL database.
 *
 * The schema comes from `php artisan migrate`; this command only copies
 * business rows. Every table is copied in foreign-key order inside one
 * PostgreSQL transaction, sequences are reset to the copied maximum id and
 * per-table row counts are compared before commit. Any value PostgreSQL would
 * reject or silently alter aborts the whole copy; `--dry-run` reports every
 * such value (table, id, column, reason; never the value) without writing.
 */
class CopySqliteToPgsql extends Command
{
    /**
     * Business tables, in foreign-key order.
     */
    public const COPIED_TABLES = [
        'users',
        'universes',
        'instruments',
        'instrument_universe',
        'daily_bars',
        'indicator_snapshots',
        'signals',
        'ingestion_runs',
        'ingestion_run_items',
        'watchlist_items',
        'saved_screeners',
        'personal_access_tokens',
    ];

    /**
     * Transient or migration-owned tables, recreated empty by `migrate`.
     */
    public const SKIPPED_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'migrations',
    ];

    private const CHUNK = 500;

    private const MAX_REPORTED_ERRORS = 50;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:copy-sqlite-to-pgsql
        {--source= : Path to the SQLite database file (default: DB_SOURCE_DATABASE)}
        {--target= : PostgreSQL connection name (default: the default connection)}
        {--dry-run : Validate every source value and report problems without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy business data from the legacy SQLite file into a freshly migrated PostgreSQL database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $source = $this->sourceConnection();
            $target = $this->targetConnection();
            $this->assertTablesClassified($source);
            $this->assertTargetReady($target);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // A read transaction gives a consistent SQLite snapshot for the whole copy.
        $source->beginTransaction();

        try {
            return $this->option('dry-run')
                ? $this->dryRun($source, $target)
                : $this->copy($source, $target);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $source->rollBack();
        }
    }

    private function dryRun(Connection $source, Connection $target): int
    {
        $errors = [];
        $counts = [];

        foreach (self::COPIED_TABLES as $table) {
            $normalizer = $this->normalizerFor($source, $target, $table);
            $counts[$table] = 0;

            foreach ($source->table($table)->lazyById(self::CHUNK, 'id') as $row) {
                $counts[$table]++;

                try {
                    $normalizer->normalize((array) $row);
                } catch (InvalidSourceValue $exception) {
                    $errors[] = $this->describe($table, $row->id, $exception);
                }
            }
        }

        $this->table(['Table', 'Source rows'], collect($counts)->map(fn (int $count, string $table) => [$table, $count])->values());

        if ($errors !== []) {
            foreach (array_slice($errors, 0, self::MAX_REPORTED_ERRORS) as $error) {
                $this->error($error);
            }

            $this->error(count($errors).' invalid value(s); the copy would abort. Nothing was written.');

            return self::FAILURE;
        }

        $this->info('Dry run passed: every value fits PostgreSQL. Nothing was written.');

        return self::SUCCESS;
    }

    private function copy(Connection $source, Connection $target): int
    {
        $rows = [];

        try {
            $target->transaction(function () use ($source, $target, &$rows): void {
                foreach (self::COPIED_TABLES as $table) {
                    $copied = $this->copyTable($source, $target, $table);
                    $expected = $source->table($table)->count();
                    $actual = $target->table($table)->count();

                    if ($copied !== $expected || $actual !== $expected) {
                        throw new RuntimeException("Row count mismatch for table [{$table}]: source {$expected}, target {$actual}.");
                    }

                    $this->resetSequence($target, $table);
                    $rows[] = [$table, $expected, $actual];
                }
            });
        } catch (RuntimeException|QueryException $exception) {
            $this->error($exception->getMessage());
            $this->error('Copy aborted and rolled back; the target database has no copied rows.');

            return self::FAILURE;
        }

        $this->table(['Table', 'Source rows', 'Target rows'], $rows);
        $this->info('Copy committed: per-table row counts match and sequences were reset.');

        return self::SUCCESS;
    }

    private function copyTable(Connection $source, Connection $target, string $table): int
    {
        $normalizer = $this->normalizerFor($source, $target, $table);
        $batch = [];
        $copied = 0;

        foreach ($source->table($table)->lazyById(self::CHUNK, 'id') as $row) {
            try {
                $batch[] = $normalizer->normalize((array) $row);
            } catch (InvalidSourceValue $exception) {
                throw new RuntimeException($this->describe($table, $row->id, $exception), previous: $exception);
            }

            if (count($batch) === self::CHUNK) {
                $copied += $this->insert($target, $table, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $copied += $this->insert($target, $table, $batch);
        }

        return $copied;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function insert(Connection $target, string $table, array $batch): int
    {
        try {
            $target->table($table)->insert($batch);
        } catch (QueryException $exception) {
            // Constraint violations (e.g. a duplicate that SQLite stored as two
            // different strings) name the key in PostgreSQL's own message.
            $first = $batch[0]['id'];
            $last = $batch[count($batch) - 1]['id'];

            throw new RuntimeException(
                "Table [{$table}] ids {$first}-{$last} rejected by PostgreSQL: ".($exception->getPrevious()?->getMessage() ?? $exception->getMessage()),
                previous: $exception,
            );
        }

        return count($batch);
    }

    private function resetSequence(Connection $target, string $table): void
    {
        $target->statement(
            "select setval(pg_get_serial_sequence(?, 'id'), coalesce(max(id), 1), max(id) is not null) from \"{$table}\"",
            [$table],
        );
    }

    private function normalizerFor(Connection $source, Connection $target, string $table): PostgresValueNormalizer
    {
        $normalizer = PostgresValueNormalizer::forTable($target, $table);
        $missing = array_diff($source->getSchemaBuilder()->getColumnListing($table), $normalizer->columnNames());

        if ($missing !== []) {
            throw new RuntimeException("Table [{$table}] source columns missing in PostgreSQL: ".implode(', ', $missing).'. Run migrations first.');
        }

        return $normalizer;
    }

    private function describe(string $table, mixed $id, InvalidSourceValue $exception): string
    {
        return "Table [{$table}] id [{$id}] {$exception->getMessage()}";
    }

    private function sourceConnection(): Connection
    {
        $path = $this->option('source');

        if ($path !== null) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("SQLite source file [{$path}] does not exist or is not readable.");
            }

            config(['database.connections.sqlite_source.database' => realpath($path)]);
            DB::purge('sqlite_source');
        }

        return DB::connection('sqlite_source');
    }

    private function targetConnection(): Connection
    {
        $name = $this->option('target') ?? config('database.default');
        $connection = DB::connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException("Target connection [{$name}] is not PostgreSQL.");
        }

        // Bulk copy and setval go straight to PostgreSQL, never through PgBouncer.
        return $connection->hasDirectConnection() ? DB::connection("{$name}::direct") : $connection;
    }

    /**
     * Refuse when the source holds a table this command does not know about,
     * so a future table is never silently left behind.
     */
    private function assertTablesClassified(Connection $source): void
    {
        $tables = array_column($source->getSchemaBuilder()->getTables(), 'name');
        $missing = array_diff(self::COPIED_TABLES, $tables);
        $unknown = array_diff($tables, self::COPIED_TABLES, self::SKIPPED_TABLES);

        if ($missing !== []) {
            throw new RuntimeException('SQLite source is missing tables: '.implode(', ', $missing).'.');
        }

        if ($unknown !== []) {
            throw new RuntimeException('SQLite source has unclassified tables: '.implode(', ', $unknown).'. Add them to the copy or skip list.');
        }
    }

    private function assertTargetReady(Connection $target): void
    {
        foreach (self::COPIED_TABLES as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("PostgreSQL table [{$table}] is missing. Run php artisan migrate --force first.");
            }

            if ($target->table($table)->exists()) {
                throw new RuntimeException("PostgreSQL table [{$table}] is not empty; the copy only targets a freshly migrated database.");
            }
        }
    }
}
