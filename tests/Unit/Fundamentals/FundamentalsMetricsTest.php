<?php

namespace Tests\Unit\Fundamentals;

use App\Models\CompanyFundamental;
use App\Services\Fundamentals\FundamentalsMetrics;
use Database\Factories\CompanyFundamentalFactory;
use Tests\TestCase;

/**
 * `fundamentals-v1` metrics computed on read (spec decision 6). The default
 * row is Apple at the 2026-06-27 10-Q (CompanyFundamentalFactory).
 */
class FundamentalsMetricsTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): CompanyFundamental
    {
        $attributes = array_merge((new CompanyFundamentalFactory)->definition(), $overrides);
        unset($attributes['instrument_id']);

        return new CompanyFundamental($attributes);
    }

    public function test_apple_metrics_with_our_close(): void
    {
        $m = FundamentalsMetrics::compute($this->row(), 250.0, '2026-10-14');

        $this->assertSame(FundamentalsMetrics::KEYS, array_keys($m));
        $this->assertSame(28.67, $m['pe_ttm']['value'], '250 / 8.72');
        $this->assertSame(['kind' => 'price', 'end' => '2026-10-14', 'form' => null], $m['pe_ttm']['period']);
        $this->assertSame(8.72, $m['eps_diluted_ttm']['value']);
        $this->assertSame(['kind' => 'ttm', 'end' => '2026-06-27', 'form' => '10-Q'], $m['eps_diluted_ttm']['period']);
        $this->assertSame(3648545.0, $m['market_cap_millions']['value'], '250 x 14,594,180,000 / 1e6');
        $this->assertSame(466823.0, $m['revenue_ttm_millions']['value']);
        $this->assertSame(14.24, $m['revenue_growth_yoy_percent']['value']);
        $this->assertSame(167959.0, $m['ebitda_millions']['value']);
        $this->assertSame(35.98, $m['ebitda_margin_percent']['value']);
        $this->assertSame(0.27, $m['net_debt_to_ebitda']['value'], '(84,344 - 39,544) / 167,959');
        // EBIT x (1 - 26,976/155,906) / (84,344 + 107,520 - 39,544) = 84.1 %.
        $this->assertSame(84.08, $m['roic_percent']['value']);
        $this->assertSame(0.42, $m['dividend_yield_percent']['value'], '1.05 / 250');
        foreach ($m as $metric) {
            $this->assertNull($metric['reason']);
        }
    }

    public function test_fiscal_year_period_when_the_10k_gives_the_ttm(): void
    {
        $inputs = ['revenue_ttm' => ['concept' => 'x', 'end' => '2026-06-30', 'form' => '10-K', 'method' => 'annual']];
        $m = FundamentalsMetrics::compute($this->row(['inputs' => $inputs, 'latest_form' => '10-K']), 100.0, '2026-10-14');

        $this->assertSame(['kind' => 'fy', 'end' => '2026-06-30', 'form' => '10-K'], $m['revenue_ttm_millions']['period']);
    }

    public function test_negative_eps_gives_no_pe(): void
    {
        $m = FundamentalsMetrics::compute($this->row(['eps_diluted_ttm' => -1.25]), 250.0, '2026-10-14');

        $this->assertSame(['value' => null, 'period' => null, 'reason' => 'negative_earnings'], $m['pe_ttm']);
        $this->assertSame(-1.25, $m['eps_diluted_ttm']['value']);
        $this->assertNull(FundamentalsMetrics::compute($this->row(['eps_diluted_ttm' => 0]), 250.0, '2026-10-14')['pe_ttm']['value']);
    }

    public function test_banks_and_insurers_get_not_applicable_metrics_with_a_reason(): void
    {
        $m = FundamentalsMetrics::compute($this->row(['is_financial' => true, 'ebit_ttm' => null, 'total_debt' => null]), 300.0, '2026-10-14');

        foreach (['ebitda_millions', 'ebitda_margin_percent', 'net_debt_to_ebitda', 'roic_percent'] as $key) {
            $this->assertSame(['value' => null, 'period' => null, 'reason' => 'financial_sector'], $m[$key], $key);
        }
        $this->assertNotNull($m['pe_ttm']['value']);
        $this->assertNotNull($m['revenue_ttm_millions']['value']);
    }

    public function test_missing_inputs_and_non_positive_ebitda(): void
    {
        $m = FundamentalsMetrics::compute($this->row(['da_ttm' => null]), 250.0, '2026-10-14');
        $this->assertSame('missing_data', $m['ebitda_millions']['reason']);
        $this->assertSame('missing_data', $m['net_debt_to_ebitda']['reason']);
        $this->assertNotNull($m['roic_percent']['value'], 'ROIC needs EBIT, not D&A');

        $m = FundamentalsMetrics::compute($this->row(['ebit_ttm' => -20e9, 'da_ttm' => 5e9]), 250.0, '2026-10-14');
        $this->assertSame(-15000.0, $m['ebitda_millions']['value']);
        $this->assertSame('negative_ebitda', $m['net_debt_to_ebitda']['reason']);
    }

    public function test_without_a_close_price_metrics_say_no_price(): void
    {
        $m = FundamentalsMetrics::compute($this->row(), null, null);

        foreach (FundamentalsMetrics::PRICE_DERIVED as $key) {
            $this->assertSame('no_price', $m[$key]['reason'], $key);
        }
        $this->assertSame(8.72, $m['eps_diluted_ttm']['value']);
    }

    public function test_dividends_not_reported_and_growth_not_meaningful(): void
    {
        $m = FundamentalsMetrics::compute($this->row(['dividends_per_share_ttm' => null, 'revenue_prior_ttm' => 0]), 250.0, '2026-10-14');

        $this->assertSame('not_reported', $m['dividend_yield_percent']['reason']);
        $this->assertSame('not_meaningful', $m['revenue_growth_yoy_percent']['reason']);
        $this->assertSame('missing_data', FundamentalsMetrics::compute($this->row(['revenue_prior_ttm' => null]), 1.0, '2026-10-14')['revenue_growth_yoy_percent']['reason']);
    }

    public function test_tax_rate_is_clamped_and_statutory_without_pretax_profit(): void
    {
        $this->assertSame(0.21, FundamentalsMetrics::taxRate(-5.0, 1.0));
        $this->assertSame(0.21, FundamentalsMetrics::taxRate(null, 1.0));
        $this->assertSame(0.5, FundamentalsMetrics::taxRate(10.0, 9.0));
        $this->assertSame(0.0, FundamentalsMetrics::taxRate(10.0, -3.0));
        $this->assertSame(0.25, FundamentalsMetrics::taxRate(100.0, 25.0));
    }

    public function test_non_positive_invested_capital_and_out_of_bounds_values_are_not_meaningful(): void
    {
        $m = FundamentalsMetrics::compute($this->row(['equity' => -60e9]), 250.0, '2026-10-14');
        $this->assertSame('not_meaningful', $m['roic_percent']['reason'], '84.3 - 60 - 39.5 < 0');

        // A tiny EPS gives a P/E beyond the sanity bound.
        $m = FundamentalsMetrics::compute($this->row(['eps_diluted_ttm' => 0.0001]), 2000.0, '2026-10-14');
        $this->assertSame('not_meaningful', $m['pe_ttm']['reason']);
    }
}
