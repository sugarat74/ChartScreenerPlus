<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\ScreenerRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * universe-registry, D10: Screener rows of non-US Listing Markets never get
 * the US sector ETF context, whatever their stored GICS sector.
 */
class UniverseRegistrySectorContextTest extends TestCase
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

    public function test_rows_of_non_us_instruments_have_null_sector_context(): void
    {
        $this->contextFixture();
        Instrument::query()->where('ticker', 'AAPL')->update(['market' => 'XMAD', 'currency' => 'EUR']);

        Artisan::call('screener:refresh');

        $aapl = ScreenerRow::query()->where('ticker', 'AAPL')->firstOrFail();
        $this->assertNull($aapl->sector_trend);
        $this->assertNull($aapl->sector_rs_rank);
        $this->assertSame('XMAD', $aapl->market);
        $this->assertSame('EUR', $aapl->currency);

        // A US stock of the same fixture keeps its context.
        $xom = ScreenerRow::query()->where('ticker', 'XOM')->firstOrFail();
        $this->assertSame('downtrend', $xom->sector_trend);
        $this->assertSame('US', $xom->market);
    }
}
