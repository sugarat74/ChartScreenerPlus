<?php

namespace Tests\Unit\Fundamentals;

use App\Services\Fundamentals\CompanyFactsReader;
use App\Services\Fundamentals\FundamentalsExtractor;
use Tests\Concerns\FakesSecEdgar;
use Tests\TestCase;

/**
 * Concept chains and fallbacks on trimmed real companyfacts fixtures
 * (provenance in Tests\Concerns\FakesSecEdgar). Expected values are the
 * research table of docs/specs/company-fundamentals-sec.md.
 */
class FundamentalsExtractorTest extends TestCase
{
    use FakesSecEdgar;

    /** @return array<string, mixed> */
    private function extract(string $name, bool $financial = false): array
    {
        return (new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray(self::secFixture("companyfacts_{$name}")), $financial);
    }

    public function test_apple_ttm_from_10k_plus_10q_year_to_date(): void
    {
        $row = $this->extract('aapl');

        $this->assertSame('ok', $row['status']);
        $this->assertSame(['2026-06-27', '2025-06-28', '2026-06-27'], [$row['period_end'], $row['prior_period_end'], $row['balance_sheet_date']]);
        $this->assertSame(['0000320193-26-000020', '10-Q', '2026-07-31'], [$row['latest_accession'], $row['latest_form'], $row['latest_filed_at']]);
        $this->assertEqualsWithDelta(466_823e6, $row['revenue_ttm'], 1e6);
        $this->assertEqualsWithDelta(14.24, ($row['revenue_ttm'] / $row['revenue_prior_ttm'] - 1) * 100, 0.01);
        $this->assertEqualsWithDelta(167_959e6, $row['ebit_ttm'] + $row['da_ttm'], 1e6, 'EBITDA = OperatingIncomeLoss + DepreciationDepletionAndAmortization');
        $this->assertEqualsWithDelta(8.72, $row['eps_diluted_ttm'], 1e-9);
        $this->assertEqualsWithDelta(84_344e6, $row['total_debt'], 1e6, 'LTD non-current + current + commercial paper');
        $this->assertEqualsWithDelta(39_544e6, $row['cash'], 1e6);
        $this->assertEqualsWithDelta(1.05, $row['dividends_per_share_ttm'], 1e-9);
        $this->assertSame('ytd', $row['inputs']['revenue_ttm']['method']);
        $this->assertSame('us-gaap:RevenueFromContractWithCustomerExcludingAssessedTax', $row['inputs']['revenue_ttm']['concept']);
        $this->assertSame('LongTermDebtNoncurrent+LongTermDebtCurrent+CommercialPaper', $row['inputs']['total_debt']['concept']);
        $this->assertSame('dei:EntityCommonStockSharesOutstanding', $row['inputs']['shares_outstanding']['concept']);
    }

    public function test_microsoft_fiscal_year_from_the_10k_and_split_d_and_a(): void
    {
        $row = $this->extract('msft');

        $this->assertSame(['2026-06-30', '10-K'], [$row['period_end'], $row['latest_form']]);
        $this->assertSame('annual', $row['inputs']['revenue_ttm']['method'], 'Q4: the 10-K figure is used, no derived quarter');
        $this->assertEqualsWithDelta(331_839e6, $row['revenue_ttm'], 1e6);
        $this->assertEqualsWithDelta(17.95, $row['eps_diluted_ttm'], 1e-9);
        $this->assertSame('us-gaap:Depreciation+us-gaap:AmortizationOfIntangibleAssets', $row['inputs']['da_ttm']['concept']);
        $this->assertEqualsWithDelta(39_000e6, $row['da_ttm'], 1e6);
        $this->assertEqualsWithDelta(194_237e6, $row['ebit_ttm'] + $row['da_ttm'], 1e6);
        $this->assertEqualsWithDelta(40_294e6, $row['total_debt'], 1e6);
        $this->assertEqualsWithDelta(3.64, $row['dividends_per_share_ttm'], 1e-9);
    }

    public function test_jpmorgan_is_financial_with_net_revenue_first(): void
    {
        $this->assertTrue(FundamentalsExtractor::isFinancialSic('6021'));
        $this->assertTrue(FundamentalsExtractor::isFinancialSic('6411'));
        $this->assertFalse(FundamentalsExtractor::isFinancialSic('6798'), 'REITs are not financial here');
        $this->assertFalse(FundamentalsExtractor::isFinancialSic('3571'));
        $this->assertFalse(FundamentalsExtractor::isFinancialSic(null));

        $row = $this->extract('jpm', true);

        $this->assertSame('ok', $row['status'], 'EBIT/debt are not required for financials');
        $this->assertSame('us-gaap:RevenuesNetOfInterestExpense', $row['inputs']['revenue_ttm']['concept']);
        $this->assertNull($row['ebit_ttm']);
        $this->assertEqualsWithDelta(23.34, $row['eps_diluted_ttm'], 1e-9);
        $this->assertEqualsWithDelta(6.00, $row['dividends_per_share_ttm'], 1e-9);
    }

    public function test_exxon_ebit_from_pretax_plus_interest_debt_current_and_cash_paid_dividends(): void
    {
        $row = $this->extract('xom');

        $this->assertSame('2026-03-31', $row['period_end'], 'companyfacts lags the 2026-08-03 10-Q');
        $this->assertSame('pretax+interest', $row['inputs']['ebit_ttm']['method']);
        $this->assertEqualsWithDelta(36_635e6 + 693e6, $row['ebit_ttm'], 1e6);
        $this->assertEqualsWithDelta(64_390e6, $row['ebit_ttm'] + $row['da_ttm'], 1e6);
        $this->assertSame('LongTermDebtAndCapitalLeaseObligations+DebtCurrent', $row['inputs']['total_debt']['concept']);
        $this->assertEqualsWithDelta(47_661e6, $row['total_debt'], 1e6);
        $this->assertSame('us-gaap:CommonStockDividendsPerShareCashPaid', $row['inputs']['dividends_per_share_ttm']['concept']);
        $this->assertEqualsWithDelta(4.04, $row['dividends_per_share_ttm'], 1e-9);
        $this->assertEqualsWithDelta(-4.4, ($row['revenue_ttm'] / $row['revenue_prior_ttm'] - 1) * 100, 0.05);
    }

    public function test_alphabet_stale_concepts_are_skipped_and_shares_fall_back(): void
    {
        $row = $this->extract('googl');

        // RevenueFromContractWithCustomer... stopped in 2025: `Revenues` is used.
        $this->assertSame('us-gaap:Revenues', $row['inputs']['revenue_ttm']['concept']);
        $this->assertSame('2026-06-30', $row['inputs']['revenue_ttm']['end']);
        // Multi-class: no dei cover count, balance-sheet shares instead.
        $this->assertSame('us-gaap:CommonStockSharesOutstanding', $row['inputs']['shares_outstanding']['concept']);
        $this->assertEqualsWithDelta(12.23e9, $row['shares_outstanding'], 1e7);
        $this->assertSame('us-gaap:Depreciation', $row['inputs']['da_ttm']['concept'], 'no amortization concept: counted as 0');
    }

    public function test_dividends_fall_back_from_a_stale_declared_concept_to_cash_paid_and_payments(): void
    {
        // Prologis pattern: DividendsPerShareDeclared only as an old FY value.
        $fact = fn (?string $start, string $end, float $val, string $form = '10-Q') => [
            'start' => $start, 'end' => $end, 'val' => $val, 'accn' => 'a-'.$end, 'form' => $form, 'filed' => '2026-05-01',
        ];
        $document = ['facts' => ['us-gaap' => [
            'EarningsPerShareDiluted' => ['units' => ['USD/shares' => [
                $fact('2025-01-01', '2025-12-31', 4.0, '10-K'), $fact('2026-01-01', '2026-03-31', 1.1), $fact('2025-01-01', '2025-03-31', 0.9),
            ]]],
            'CommonStockDividendsPerShareDeclared' => ['units' => ['USD/shares' => [$fact('2021-01-01', '2021-12-31', 0.03, '10-K')]]],
            'CommonStockDividendsPerShareCashPaid' => ['units' => ['USD/shares' => [
                $fact('2025-01-01', '2025-12-31', 4.0, '10-K'), $fact('2026-01-01', '2026-03-31', 1.01), $fact('2025-01-01', '2025-03-31', 0.91),
            ]]],
        ], 'dei' => [
            'EntityCommonStockSharesOutstanding' => ['units' => ['shares' => [$fact(null, '2026-04-20', 100.0)]]],
        ]]];

        $row = (new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray($document), false);
        $this->assertSame('us-gaap:CommonStockDividendsPerShareCashPaid', $row['inputs']['dividends_per_share_ttm']['concept']);
        $this->assertEqualsWithDelta(4.10, $row['dividends_per_share_ttm'], 1e-9);
        $this->assertSame('partial', $row['status'], 'no revenue, EBIT or balance sheet');

        // Without per-share concepts: dividends paid / shares outstanding.
        unset($document['facts']['us-gaap']['CommonStockDividendsPerShareCashPaid']);
        $document['facts']['us-gaap']['PaymentsOfDividends'] = ['units' => ['USD' => [
            $fact('2025-01-01', '2025-12-31', 400.0, '10-K'), $fact('2026-01-01', '2026-03-31', 110.0), $fact('2025-01-01', '2025-03-31', 100.0),
        ]]];
        $row = (new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray($document), false);
        $this->assertSame('dividends_paid/shares', $row['inputs']['dividends_per_share_ttm']['method']);
        $this->assertEqualsWithDelta(4.10, $row['dividends_per_share_ttm'], 1e-9);

        // Neither: not reported.
        unset($document['facts']['us-gaap']['PaymentsOfDividends']);
        $this->assertNull((new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray($document), false)['dividends_per_share_ttm']);
    }

    public function test_multi_class_cover_shares_of_one_filing_are_summed(): void
    {
        $share = fn (float $val, string $end, string $accn) => ['end' => $end, 'val' => $val, 'accn' => $accn, 'form' => '10-Q', 'filed' => '2026-08-01'];
        $document = ['facts' => [
            'us-gaap' => ['NetIncomeLoss' => ['units' => ['USD' => [['start' => '2026-04-01', 'end' => '2026-06-30', 'val' => 5.0, 'accn' => 'x', 'form' => '10-Q', 'filed' => '2026-08-01']]]]],
            'dei' => ['EntityCommonStockSharesOutstanding' => ['units' => ['shares' => [
                $share(300.0, '2026-07-20', 'x'), $share(100.0, '2026-07-20', 'x'), $share(999.0, '2026-04-20', 'old'),
            ]]]],
        ]];

        $row = (new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray($document), false);

        $this->assertSame('2026-06-30', $row['period_end'], 'net income is the anchor fallback');
        $this->assertSame(400.0, $row['shares_outstanding']);
        $this->assertSame('2026-07-20', $row['shares_as_of']);
    }

    public function test_no_eps_or_net_income_means_no_data(): void
    {
        $row = (new FundamentalsExtractor)->extract(CompanyFactsReader::fromArray(['facts' => []]), false);

        $this->assertSame('no_data', $row['status']);
        $this->assertNull($row['period_end']);
    }

    public function test_reader_keeps_only_periodic_forms_but_remembers_every_accession(): void
    {
        $reader = CompanyFactsReader::fromArray(['facts' => ['us-gaap' => [
            'Revenues' => ['units' => ['USD' => [
                ['start' => '2026-01-01', 'end' => '2026-03-31', 'val' => 1, 'accn' => 'q', 'form' => '10-Q', 'filed' => '2026-05-01'],
                ['start' => '2026-01-01', 'end' => '2026-03-31', 'val' => 2, 'accn' => 'k8', 'form' => '8-K', 'filed' => '2026-04-20'],
            ]]],
            'SomethingElse' => ['units' => ['USD' => [['end' => '2026-03-31', 'val' => 3, 'accn' => 'other', 'form' => '10-Q', 'filed' => '2026-05-01']]]],
        ]]]);

        $this->assertCount(1, $reader->facts('us-gaap:Revenues'));
        $this->assertSame([], $reader->facts('us-gaap:SomethingElse'));
        $this->assertTrue($reader->hasAccession('k8'));
        $this->assertTrue($reader->hasAccession('other'));
        $this->assertFalse($reader->hasAccession('missing'));
    }
}
