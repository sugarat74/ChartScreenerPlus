<?php

namespace Database\Factories;

use App\Models\CompanyFundamental;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default state: Apple's inputs at the 2026-06-27 10-Q
 * (tests/fixtures/sec/companyfacts_aapl.json).
 *
 * @extends Factory<CompanyFundamental>
 */
class CompanyFundamentalFactory extends Factory
{
    public function definition(): array
    {
        $ttm = fn (string $concept, string $method = 'ytd'): array => [
            'concept' => $concept, 'start' => '2025-06-29', 'end' => '2026-06-27',
            'form' => '10-Q', 'accession' => '0000320193-26-000020', 'method' => $method,
        ];

        return [
            'instrument_id' => Instrument::factory(),
            'cik' => '0000320193',
            'sic' => '3571',
            'sic_description' => 'Electronic Computers',
            'is_financial' => false,
            'status' => CompanyFundamental::STATUS_OK,
            'currency' => 'USD',
            'period_end' => '2026-06-27',
            'prior_period_end' => '2025-06-28',
            'balance_sheet_date' => '2026-06-27',
            'shares_as_of' => '2026-07-17',
            'latest_accession' => '0000320193-26-000020',
            'latest_form' => '10-Q',
            'latest_filed_at' => '2026-07-31',
            'pending_accession' => null,
            'pending_form' => null,
            'pending_filed_at' => null,
            'revenue_ttm' => 466823000000,
            'revenue_prior_ttm' => 408625000000,
            'ebit_ttm' => 154859000000,
            'pretax_ttm' => 155906000000,
            'tax_ttm' => 26976000000,
            'da_ttm' => 13100000000,
            'eps_diluted_ttm' => 8.72,
            'dividends_per_share_ttm' => 1.05,
            'total_debt' => 84344000000,
            'cash' => 39544000000,
            'equity' => 107520000000,
            'shares_outstanding' => 14594180000,
            'inputs' => [
                'revenue_ttm' => $ttm('us-gaap:RevenueFromContractWithCustomerExcludingAssessedTax'),
                'ebit_ttm' => $ttm('us-gaap:OperatingIncomeLoss'),
                'eps_diluted_ttm' => $ttm('us-gaap:EarningsPerShareDiluted'),
            ],
            'fetched_at' => now(),
            'last_error' => null,
        ];
    }

    /** A bank (SIC 6021): EBITDA-based metrics and ROIC do not apply. */
    public function financial(): static
    {
        return $this->state(fn (): array => [
            'sic' => '6021', 'sic_description' => 'National Commercial Banks', 'is_financial' => true,
            'ebit_ttm' => null, 'total_debt' => null,
        ]);
    }
}
