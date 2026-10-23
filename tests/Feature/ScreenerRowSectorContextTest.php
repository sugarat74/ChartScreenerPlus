<?php

namespace Tests\Feature;

use App\Jobs\RunMarketContextCommand;
use App\Models\ScreenerRow;
use App\Services\MarketContext\MarketContextRunLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * The `screener_rows` sector context columns (docs/specs/market-context-patterns.md,
 * C4; Scenario 5): filled from the latest Sector Snapshot date by stored GICS
 * sector, null when the stock has no or an unmapped sector, when the snapshot
 * is more than 7 days older than the row's bar, or when there are no
 * snapshots. ETFs still get no row.
 */
class ScreenerRowSectorContextTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{0: ?string, 1: ?int}> ticker => [trend, rank] */
    private function columns(): array
    {
        return ScreenerRow::query()->orderBy('ticker')->get()
            ->mapWithKeys(fn (ScreenerRow $row): array => [$row->ticker => [$row->sector_trend, $row->sector_rs_rank]])
            ->all();
    }

    public function test_the_columns_are_filled_by_sector(): void
    {
        $this->contextFixture();

        Artisan::call('screener:refresh');

        $this->assertSame([
            'AAPL' => ['uptrend', 1],
            'NEE' => ['mixed', null],
            'ODD' => [null, null],
            'XOM' => ['downtrend', 10],
            'ZZZ' => [null, null],
        ], $this->columns());
        // ETFs (the sector SPDRs and SPY) never get a row, even with bars.
        $this->assertSame(0, ScreenerRow::query()->whereIn('ticker', ['XLK', 'SPY'])->count());
    }

    public function test_empty_snapshots_leave_the_columns_null(): void
    {
        $this->contextStocks();

        Artisan::call('screener:refresh');

        $this->assertSame([[null, null]], array_values(array_unique($this->columns(), SORT_REGULAR)));
    }

    public function test_a_snapshot_8_days_older_than_the_bar_is_stale_and_7_days_is_fresh(): void
    {
        // AAPL's bar is 2026-10-22; 2026-10-14 is 8 days older.
        $this->sectorRows('2026-10-14', self::CONTEXT_SECTOR_ROWS);
        $this->contextStocks();

        Artisan::call('screener:refresh');
        $this->assertSame([null, null], $this->columns()['AAPL']);

        DB::table('market_sector_snapshots')->delete();
        $this->sectorRows('2026-10-15', self::CONTEXT_SECTOR_ROWS);

        Artisan::call('screener:refresh');
        $this->assertSame(['uptrend', 1], $this->columns()['AAPL']);
    }

    public function test_a_snapshot_newer_than_the_bar_is_fresh(): void
    {
        $this->sectorRows(self::LATEST, self::CONTEXT_SECTOR_ROWS);
        $this->contextStocks('2026-10-20');

        Artisan::call('screener:refresh');

        $this->assertSame(['downtrend', 10], $this->columns()['XOM']);
    }

    public function test_the_admin_rerun_of_the_compute_refreshes_the_rows(): void
    {
        $this->contextStocks();
        Artisan::call('screener:refresh');
        $this->assertSame([null, null], $this->columns()['AAPL']);

        // The compute itself finds no ETF bars here; the rows still pick up
        // the snapshot written since the last refresh.
        $this->sectorRows(self::LATEST, self::CONTEXT_SECTOR_ROWS);
        MarketContextRunLog::queued('market-context:compute');
        (new RunMarketContextCommand('market-context:compute'))->handle();

        $this->assertSame(['uptrend', 1], $this->columns()['AAPL']);
    }
}
