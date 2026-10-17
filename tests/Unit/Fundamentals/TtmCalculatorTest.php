<?php

namespace Tests\Unit\Fundamentals;

use App\Services\Fundamentals\TtmCalculator;
use PHPUnit\Framework\TestCase;

/**
 * TTM arithmetic (docs/specs/company-fundamentals-sec.md decisions 2-3) on
 * synthetic facts shaped like companyfacts entries. A calendar-year company:
 * FY2025 revenue 400, quarters of 2025 = 90/95/100/115, of 2026 = 110/120.
 */
class TtmCalculatorTest extends TestCase
{
    private TtmCalculator $ttm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ttm = new TtmCalculator;
    }

    /** @return array<string, mixed> */
    private static function fact(?string $start, string $end, float $val, string $filed = '2026-01-01', string $form = '10-Q', string $accn = 'acc', int $fy = 2026, string $fp = 'Q1'): array
    {
        return compact('start', 'end', 'val', 'filed', 'form', 'accn', 'fy', 'fp');
    }

    /** @return list<array<string, mixed>> */
    private static function history(): array
    {
        return [
            self::fact('2024-01-01', '2024-12-31', 360, '2025-02-10', '10-K', 'k24'),
            self::fact('2025-01-01', '2025-12-31', 400, '2026-02-10', '10-K', 'k25'),
            // 2025 10-Qs (quarter and YTD).
            self::fact('2025-01-01', '2025-03-31', 90, '2025-05-01', '10-Q', 'q125'),
            self::fact('2025-04-01', '2025-06-30', 95, '2025-08-01', '10-Q', 'q225'),
            self::fact('2025-01-01', '2025-06-30', 185, '2025-08-01', '10-Q', 'q225'),
            self::fact('2025-07-01', '2025-09-30', 100, '2025-11-01', '10-Q', 'q325'),
            self::fact('2025-01-01', '2025-09-30', 285, '2025-11-01', '10-Q', 'q325'),
            // 2026 10-Qs repeat the year-ago comparatives.
            self::fact('2026-01-01', '2026-03-31', 110, '2026-05-01', '10-Q', 'q126'),
            self::fact('2025-01-01', '2025-03-31', 90, '2026-05-01', '10-Q', 'q126'),
            self::fact('2026-04-01', '2026-06-30', 120, '2026-08-01', '10-Q', 'q226'),
            self::fact('2026-01-01', '2026-06-30', 230, '2026-08-01', '10-Q', 'q226'),
            self::fact('2025-01-01', '2025-06-30', 185, '2026-08-01', '10-Q', 'q226'),
        ];
    }

    public function test_annual_fact_at_the_anchor_is_used_directly_no_q4_is_derived(): void
    {
        $result = $this->ttm->ttm(array_slice(self::history(), 0, 7), '2025-12-31');

        $this->assertSame(400.0, $result['value']);
        $this->assertSame(TtmCalculator::METHOD_ANNUAL, $result['method']);
        $this->assertSame(['2025-01-01', '2025-12-31', '10-K', 'k25'], [$result['start'], $result['end'], $result['form'], $result['accn']]);
    }

    public function test_year_to_date_ttm_is_last_fy_plus_ytd_minus_prior_ytd(): void
    {
        // H1 2026: 400 + 230 - 185 = 445 (= 100 + 115 + 110 + 120).
        $h1 = $this->ttm->ttm(self::history(), '2026-06-30');
        $this->assertSame(445.0, $h1['value']);
        $this->assertSame(TtmCalculator::METHOD_YTD, $h1['method']);
        $this->assertSame(['2025-07-01', '2026-06-30', '10-Q', 'q226'], [$h1['start'], $h1['end'], $h1['form'], $h1['accn']]);

        // Q1 2026: 400 + 110 - 90 = 420.
        $q1 = $this->ttm->ttm(self::history(), '2026-03-31');
        $this->assertSame(420.0, $q1['value']);
        $this->assertSame('q126', $q1['accn']);

        // Q3 2025 (9 months YTD): 360 + 285 - (Q1..Q3 2024 unknown) -> no comparative.
        $this->assertNull($this->ttm->ttm(self::history(), '2025-09-30'));
    }

    public function test_cash_flow_items_reported_only_year_to_date_work_the_same(): void
    {
        // A cash-flow concept: no quarter facts at all, YTD only (3.2/6.7/10.0 pattern).
        $facts = [
            self::fact('2024-10-01', '2025-09-30', 11.0, '2025-11-01', '10-K', 'k'),
            self::fact('2024-10-01', '2024-12-31', 3.0, '2025-02-01', '10-Q', 'q1'),
            self::fact('2024-10-01', '2025-03-31', 6.0, '2025-05-01', '10-Q', 'q2'),
            self::fact('2024-10-01', '2025-06-30', 8.5, '2025-08-01', '10-Q', 'q3'),
            self::fact('2025-10-01', '2025-12-31', 3.2, '2026-02-01', '10-Q', 'q1n'),
            self::fact('2025-10-01', '2026-03-31', 6.7, '2026-05-01', '10-Q', 'q2n'),
            self::fact('2025-10-01', '2026-06-30', 10.0, '2026-08-01', '10-Q', 'q3n'),
        ];

        $this->assertEqualsWithDelta(12.5, $this->ttm->ttm($facts, '2026-06-30')['value'], 1e-9);
        $this->assertEqualsWithDelta(11.7, $this->ttm->ttm($facts, '2026-03-31')['value'], 1e-9);
        $this->assertEqualsWithDelta(11.2, $this->ttm->ttm($facts, '2025-12-31')['value'], 1e-9);
    }

    public function test_four_contiguous_quarters_are_the_fallback(): void
    {
        // No annual fact: only quarters (e.g. a concept dropped from the 10-K).
        $facts = array_values(array_filter(self::history(), fn (array $f): bool => $f['form'] === '10-Q' && TtmCalculator::days($f['start'], $f['end']) < 100));
        $facts[] = self::fact('2025-10-01', '2025-12-31', 115, '2026-02-10', '10-Q', 'q4');

        $result = $this->ttm->ttm($facts, '2026-06-30');

        $this->assertSame(445.0, $result['value'], 'Q3 2025 + Q4 2025 + Q1 2026 + Q2 2026 = 100 + 115 + 110 + 120');
        $this->assertSame(TtmCalculator::METHOD_QUARTERS, $result['method']);
        $this->assertSame('2025-07-01', $result['start']);
    }

    public function test_restated_fact_with_a_later_filing_wins(): void
    {
        $facts = self::history();
        // The FY2026 Q2 10-Q restates the H1 2025 comparative from 185 to 180.
        $facts[] = self::fact('2025-01-01', '2025-06-30', 180, '2026-08-02', '10-Q', 'q226');

        $this->assertSame(450.0, $this->ttm->ttm($facts, '2026-06-30')['value'], '400 + 230 - 180');
    }

    public function test_amended_10_q_a_replaces_the_original(): void
    {
        $facts = self::history();
        $facts[] = self::fact('2026-01-01', '2026-06-30', 240, '2026-09-15', '10-Q/A', 'q226a');

        $result = $this->ttm->ttm($facts, '2026-06-30');

        $this->assertSame(455.0, $result['value']);
        $this->assertSame(['10-Q/A', 'q226a'], [$result['form'], $result['accn']]);
    }

    public function test_missing_comparative_gives_null_without_quarters(): void
    {
        $facts = array_values(array_filter(self::history(), fn (array $f): bool => ! ($f['start'] === '2025-01-01' && $f['end'] === '2025-06-30')));
        $facts = array_values(array_filter($facts, fn (array $f): bool => TtmCalculator::days($f['start'], $f['end']) > 100));

        $this->assertNull($this->ttm->ttm($facts, '2026-06-30'));
    }

    public function test_a_ttm_must_end_at_the_anchor(): void
    {
        // Facts end 2025-12-31; asking for the 2026-06-30 anchor gives nothing.
        $stale = array_slice(self::history(), 0, 2);

        $this->assertNull($this->ttm->ttm($stale, '2026-06-30'));
        $this->assertNotNull($this->ttm->ttm($stale, '2026-01-02'), 'within the 3-day tolerance');
        $this->assertNull($this->ttm->ttm($stale, '2026-01-05'));
    }

    public function test_labels_come_from_dates_never_from_fy_and_fp(): void
    {
        // AAPL case: the FY2024 annual figure appears inside the FY2025 10-K
        // tagged fy=2025, fp=FY. The period must still be FY2024's dates.
        $facts = [
            self::fact('2023-10-01', '2024-09-28', 11.4, '2025-10-31', '10-K', 'k25', 2025, 'FY'),
            self::fact('2024-09-29', '2025-09-27', 11.7, '2025-10-31', '10-K', 'k25', 2025, 'FY'),
        ];

        $result = $this->ttm->ttm($facts, '2024-09-28');

        $this->assertSame(11.4, $result['value']);
        $this->assertSame(['2023-10-01', '2024-09-28'], [$result['start'], $result['end']]);
        $this->assertSame('2025-09-27', $this->ttm->anchor($facts));
    }

    public function test_prior_ttm_is_the_same_calculation_one_year_earlier(): void
    {
        // 52/53-week calendars move the end by a few days year over year.
        $facts = self::history();
        $this->assertNull($this->ttm->priorTtm($facts, '2026-06-27'), 'no H1 2024 comparative yet');

        $facts[] = self::fact('2024-01-01', '2024-06-30', 170, '2025-08-01', '10-Q', 'q225');
        $prior = $this->ttm->priorTtm($facts, '2026-06-27');

        $this->assertSame(['2025-06-30', TtmCalculator::METHOD_YTD], [$prior['end'], $prior['method']]);
        $this->assertSame(375.0, $prior['value'], '360 + 185 - 170');
    }

    public function test_instants_take_the_balance_sheet_date_and_latest_filing(): void
    {
        $facts = [
            self::fact(null, '2026-06-30', 50, '2026-08-01'),
            self::fact(null, '2026-06-30', 52, '2026-09-01', '10-Q/A'),
            self::fact(null, '2025-12-31', 40, '2026-02-10', '10-K'),
        ];

        $this->assertSame(52.0, $this->ttm->instantAt($facts, '2026-06-30')['val']);
        $this->assertSame(52.0, $this->ttm->latestInstant($facts)['val']);
        $this->assertNull($this->ttm->instantAt($facts, '2026-03-31'));
    }
}
