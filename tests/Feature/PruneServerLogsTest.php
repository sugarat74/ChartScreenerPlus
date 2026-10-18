<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Server log retention (legal-privacy-publication): daily log files dated
 * `today - legal.server_log_retention_days` or earlier are deleted, newer ones
 * and any other file are kept, and the `daily` channel writes 0660 files.
 */
class PruneServerLogsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'chartiko-logs-prune-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config([
            'logging.channels.daily.path' => $this->directory.DIRECTORY_SEPARATOR.'laravel.log',
            'legal.server_log_retention_days' => 14,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-18 03:25:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function touch(string $name): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.$name, "entry\n");
    }

    private function exists(string $name): bool
    {
        return is_file($this->directory.DIRECTORY_SEPARATOR.$name);
    }

    public function test_deletes_daily_files_at_or_beyond_the_retention_and_keeps_the_rest(): void
    {
        $files = [
            'laravel-2026-10-18.log',   // today
            'laravel-2026-10-05.log',   // today - 13
            'laravel-2026-10-04.log',   // today - 14
            'laravel-2026-09-01.log',
            'laravel-2025-12-31.log',
            'laravel.log',
            'other.log',
            'laravel-2026-01-01.log.gz',
            'worker-2026-01-01.log',
            'laravel-2026-02-31.log',   // not a real date
        ];
        foreach ($files as $file) {
            $this->touch($file);
        }

        $this->artisan('logs:prune')
            ->expectsOutputToContain('Deleted 3 server log file(s) older than 14 days.')
            ->assertSuccessful();

        $this->assertFalse($this->exists('laravel-2026-10-04.log'));
        $this->assertFalse($this->exists('laravel-2026-09-01.log'));
        $this->assertFalse($this->exists('laravel-2025-12-31.log'));
        foreach (['laravel-2026-10-18.log', 'laravel-2026-10-05.log', 'laravel.log', 'other.log', 'laravel-2026-01-01.log.gz', 'worker-2026-01-01.log', 'laravel-2026-02-31.log'] as $kept) {
            $this->assertTrue($this->exists($kept), "{$kept} must be kept");
        }
    }

    public function test_retention_follows_configuration_and_never_drops_below_one_day(): void
    {
        $this->touch('laravel-2026-10-18.log');
        $this->touch('laravel-2026-10-17.log');
        $this->touch('laravel-2026-10-11.log');

        config(['legal.server_log_retention_days' => 7]);
        $this->artisan('logs:prune')->expectsOutputToContain('Deleted 1 server log file(s) older than 7 days.')->assertSuccessful();
        $this->assertFalse($this->exists('laravel-2026-10-11.log'));
        $this->assertTrue($this->exists('laravel-2026-10-17.log'));

        config(['legal.server_log_retention_days' => 0]);
        $this->artisan('logs:prune')->expectsOutputToContain('older than 1 days.')->assertSuccessful();
        $this->assertFalse($this->exists('laravel-2026-10-17.log'));
        $this->assertTrue($this->exists('laravel-2026-10-18.log'));
    }

    public function test_a_missing_directory_is_not_an_error(): void
    {
        config(['logging.channels.daily.path' => $this->directory.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'laravel.log']);

        $this->artisan('logs:prune')->expectsOutputToContain('Deleted 0 server log file(s)')->assertSuccessful();
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('logs:prune')->assertSuccessful();
    }

    public function test_daily_log_files_are_group_writable(): void
    {
        $this->assertSame(0660, config('logging.channels.daily.permission'));
    }
}
