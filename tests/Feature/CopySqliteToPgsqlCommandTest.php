<?php

namespace Tests\Feature;

use App\Console\Commands\CopySqliteToPgsql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `db:copy-sqlite-to-pgsql`: copies a migrated SQLite file into a freshly
 * migrated PostgreSQL database. The copy scenarios need PostgreSQL and run in
 * CI's `test-pgsql` job; the refusal scenarios run on every driver.
 *
 * This test manages the target schema itself (migrate:fresh before and after)
 * instead of RefreshDatabase, because the command commits through its own
 * direct connection rather than the test's wrapping transaction.
 */
class CopySqliteToPgsqlCommandTest extends TestCase
{
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = tempnam(sys_get_temp_dir(), 'chartiko-source-');
        config(['database.connections.sqlite_source.database' => $this->sourcePath]);
        $this->artisan('migrate', ['--database' => 'sqlite_source', '--force' => true])->assertSuccessful();

        if ($this->onPostgres()) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        if ($this->onPostgres()) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }

        DB::purge('sqlite_source');
        @unlink($this->sourcePath);

        parent::tearDown();
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('Copy scenarios need PostgreSQL; they run in the CI test-pgsql job.');
        }
    }

    private function source(string $table): Builder
    {
        return DB::connection('sqlite_source')->table($table);
    }

    /**
     * Rows shaped the way the production SQLite file stores them: 0/1
     * booleans, decimals as reals, Eloquent midnight datetimes in date
     * columns, JSON as text, and id gaps.
     */
    private function seedSource(): void
    {
        $at = '2026-09-01 10:00:00';

        $this->source('users')->insert([
            ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'hash', 'role' => 'admin', 'created_at' => $at, 'updated_at' => $at],
            ['id' => 5, 'name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'hash', 'role' => 'user', 'created_at' => $at, 'updated_at' => $at],
        ]);
        $this->source('universes')->insert(['id' => 1, 'name' => 'S&P 500', 'slug' => 'sp500', 'created_at' => $at, 'updated_at' => $at]);
        $this->source('instruments')->insert([
            ['id' => 1, 'ticker' => 'NVDA', 'company' => 'NVIDIA', 'sector' => 'Technology', 'exchange' => 'NASDAQ', 'active' => 1, 'created_at' => $at, 'updated_at' => $at],
            ['id' => 2, 'ticker' => 'OLD', 'company' => 'Delisted', 'sector' => null, 'exchange' => null, 'active' => 0, 'created_at' => $at, 'updated_at' => $at],
        ]);
        $this->source('instrument_universe')->insert(['id' => 1, 'universe_id' => 1, 'instrument_id' => 1, 'created_at' => $at, 'updated_at' => $at]);
        $this->source('daily_bars')->insert([
            ['id' => 1, 'instrument_id' => 1, 'date' => '2026-09-01', 'open' => 120.1, 'high' => 125.25, 'low' => 119.5, 'close' => 123.4567, 'volume' => 9_000_000_000, 'created_at' => $at, 'updated_at' => $at],
            ['id' => 2, 'instrument_id' => 1, 'date' => '2026-09-02 00:00:00', 'open' => 123.5, 'high' => 131, 'low' => 123, 'close' => 130.5, 'volume' => 42, 'created_at' => $at, 'updated_at' => $at],
        ]);
        $this->source('indicator_snapshots')->insert(['id' => 1, 'instrument_id' => 1, 'date' => '2026-09-02', 'rsi14' => 55.1234, 'rvol' => 1.5, 'created_at' => $at, 'updated_at' => $at]);
        $this->source('signals')->insert(['id' => 1, 'instrument_id' => 1, 'date' => '2026-09-02', 'type' => 'ma_cross', 'metadata' => '{"fast":20,"slow":50}', 'created_at' => $at, 'updated_at' => $at]);
        $this->source('ingestion_runs')->insert(['id' => 1, 'status' => 'succeeded', 'universe_id' => 1, 'started_at' => $at, 'finished_at' => $at, 'total' => 1, 'succeeded' => 1, 'failed' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $this->source('ingestion_run_items')->insert(['id' => 1, 'ingestion_run_id' => 1, 'instrument_id' => 1, 'status' => 'succeeded', 'bars_stored' => 2, 'message' => null, 'created_at' => $at, 'updated_at' => $at]);
        $this->source('watchlist_items')->insert(['id' => 3, 'user_id' => 5, 'instrument_id' => 1, 'created_at' => $at, 'updated_at' => $at]);
        $this->source('saved_screeners')->insert(['id' => 1, 'user_id' => 5, 'name' => 'Momentum', 'filters' => '{"signal":["ma_cross"],"rsi_min":30}', 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_it_refuses_a_target_that_is_not_postgres(): void
    {
        $this->artisan('db:copy-sqlite-to-pgsql', ['--target' => 'sqlite_source'])
            ->expectsOutputToContain('is not PostgreSQL')
            ->assertFailed();
    }

    public function test_it_refuses_a_missing_source_file(): void
    {
        $this->artisan('db:copy-sqlite-to-pgsql', ['--source' => $this->sourcePath.'-missing'])
            ->expectsOutputToContain('does not exist or is not readable')
            ->assertFailed();
    }

    public function test_it_copies_every_business_table_exactly_and_resets_sequences(): void
    {
        $this->requirePostgres();
        $this->seedSource();
        $this->source('sessions')->insert(['id' => 'transient', 'payload' => 'x', 'last_activity' => 1]);

        $this->artisan('db:copy-sqlite-to-pgsql')
            ->expectsOutputToContain('Copy committed')
            ->assertSuccessful();

        foreach (CopySqliteToPgsql::COPIED_TABLES as $table) {
            $this->assertSame($this->source($table)->count(), DB::table($table)->count(), $table);
        }

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame('2026-09-02', DB::table('daily_bars')->where('id', 2)->value('date'));
        $this->assertSame('123.4567', DB::table('daily_bars')->where('id', 1)->value('close'));
        $this->assertEquals(9_000_000_000, DB::table('daily_bars')->where('id', 1)->value('volume'));
        $this->assertFalse(DB::table('instruments')->where('id', 2)->value('active'));
        $this->assertTrue(DB::table('instruments')->where('id', 1)->value('active'));
        $this->assertNull(DB::table('indicator_snapshots')->value('sma200'));
        $this->assertSame(
            ['signal' => ['ma_cross'], 'rsi_min' => 30],
            json_decode(DB::table('saved_screeners')->value('filters'), true),
        );
        $this->assertSame('2026-09-01 10:00:00', DB::table('users')->where('id', 5)->value('created_at'));

        // Sequences continue after the copied maximum, including empty tables.
        $userId = DB::table('users')->insertGetId(['name' => 'New', 'email' => 'new@example.test', 'password' => 'hash']);
        $watchId = DB::table('watchlist_items')->insertGetId(['user_id' => $userId, 'instrument_id' => 2]);
        $tokenId = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $userId, 'name' => 't', 'token' => str_repeat('a', 64),
        ]);

        $this->assertSame(6, $userId);
        $this->assertSame(4, $watchId);
        $this->assertSame(1, $tokenId);
    }

    public function test_an_invalid_source_value_aborts_the_whole_copy(): void
    {
        $this->requirePostgres();
        $this->seedSource();
        $this->source('instruments')->where('id', 2)->update(['company' => str_repeat('x', 300)]);

        $this->artisan('db:copy-sqlite-to-pgsql')
            ->expectsOutputToContain('Table [instruments] id [2] column [company]: string longer than character varying(255)')
            ->expectsOutputToContain('rolled back')
            ->assertFailed();

        // users and universes were copied before instruments: nothing remains.
        foreach (CopySqliteToPgsql::COPIED_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_dry_run_reports_every_invalid_value_without_writing(): void
    {
        $this->requirePostgres();
        $this->seedSource();
        $this->source('instruments')->where('id', 2)->update(['company' => str_repeat('x', 300)]);
        $this->source('daily_bars')->where('id', 1)->update(['date' => '2026-02-30']);

        $this->artisan('db:copy-sqlite-to-pgsql', ['--dry-run' => true])
            ->expectsOutputToContain('Table [instruments] id [2] column [company]')
            ->expectsOutputToContain('Table [daily_bars] id [1] column [date]: not a calendar date')
            ->expectsOutputToContain('2 invalid value(s)')
            ->assertFailed();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_dry_run_passes_on_clean_data_without_writing(): void
    {
        $this->requirePostgres();
        $this->seedSource();

        $this->artisan('db:copy-sqlite-to-pgsql', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run passed')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_it_refuses_a_target_that_already_has_data(): void
    {
        $this->requirePostgres();
        $this->seedSource();
        DB::table('universes')->insert(['name' => 'Existing', 'slug' => 'existing']);

        $this->artisan('db:copy-sqlite-to-pgsql')
            ->expectsOutputToContain('PostgreSQL table [universes] is not empty')
            ->assertFailed();

        $this->assertSame(1, DB::table('universes')->count());
    }

    public function test_a_duplicate_key_that_sqlite_allowed_aborts_with_the_postgres_message(): void
    {
        $this->requirePostgres();
        $this->seedSource();
        // SQLite treats '2026-09-03' and '2026-09-03 00:00:00' as distinct; PostgreSQL dates do not.
        $this->source('indicator_snapshots')->insert([
            ['id' => 2, 'instrument_id' => 1, 'date' => '2026-09-03', 'created_at' => null, 'updated_at' => null],
            ['id' => 3, 'instrument_id' => 1, 'date' => '2026-09-03 00:00:00', 'created_at' => null, 'updated_at' => null],
        ]);

        $this->artisan('db:copy-sqlite-to-pgsql')
            ->expectsOutputToContain('Table [indicator_snapshots] ids 1-3 rejected by PostgreSQL')
            // The duplicated value must never be printed.
            ->doesntExpectOutputToContain('2026-09-03')
            ->assertFailed();

        $this->assertSame(0, DB::table('daily_bars')->count());
    }
}
