<?php

namespace App\Services\Fundamentals;

/**
 * Turns a reduced companyfacts document into the stored fundamentals inputs
 * with provenance (spec decisions 2, 4 and 5). Pure: no I/O.
 *
 * Every TTM input must end at the anchor (the newest diluted-EPS period end,
 * fallback net income) within `TtmCalculator::ANCHOR_TOLERANCE_DAYS`; a
 * concept whose newest TTM is older is skipped and the next concept of its
 * chain is tried. Balance-sheet inputs are read at the newest
 * `StockholdersEquity` date.
 */
class FundamentalsExtractor
{
    public const STATUS_OK = 'ok';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NO_DATA = 'no_data';

    private const REVENUE = [
        'us-gaap:Revenues',
        'us-gaap:RevenueFromContractWithCustomerExcludingAssessedTax',
        'us-gaap:RevenueFromContractWithCustomerIncludingAssessedTax',
        'us-gaap:SalesRevenueNet',
        'us-gaap:RevenuesNetOfInterestExpense',
    ];

    private const PRETAX = [
        'us-gaap:IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest',
        'us-gaap:IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments',
    ];

    private const INTEREST = ['us-gaap:InterestExpense', 'us-gaap:InterestExpenseNonoperating', 'us-gaap:InterestExpenseDebt'];

    private const DA = ['us-gaap:DepreciationDepletionAndAmortization', 'us-gaap:DepreciationAndAmortization', 'us-gaap:DepreciationAmortizationAndAccretionNet'];

    private const EPS = ['us-gaap:EarningsPerShareDiluted', 'us-gaap:EarningsPerShareBasicAndDiluted'];

    private const DPS = ['us-gaap:CommonStockDividendsPerShareDeclared', 'us-gaap:CommonStockDividendsPerShareCashPaid'];

    private const DIVIDENDS_PAID = ['us-gaap:PaymentsOfDividendsCommonStock', 'us-gaap:PaymentsOfDividends'];

    private const CASH = ['us-gaap:CashAndCashEquivalentsAtCarryingValue', 'us-gaap:CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents'];

    public function __construct(private readonly TtmCalculator $ttm = new TtmCalculator) {}

    public static function isFinancialSic(?string $sic): bool
    {
        if ($sic === null || ! ctype_digit($sic)) {
            return false;
        }
        [$from, $to] = array_map('intval', (array) config('fundamentals.financial_sic', [6000, 6411]));

        return (int) $sic >= $from && (int) $sic <= $to;
    }

    /**
     * @return array<string, mixed> Column values for `company_fundamentals` (plus `inputs`).
     */
    public function extract(CompanyFactsReader $facts, bool $isFinancial): array
    {
        $empty = [
            'status' => self::STATUS_NO_DATA, 'period_end' => null, 'prior_period_end' => null,
            'balance_sheet_date' => null, 'shares_as_of' => null,
            'latest_accession' => null, 'latest_form' => null, 'latest_filed_at' => null,
            'revenue_ttm' => null, 'revenue_prior_ttm' => null, 'ebit_ttm' => null, 'pretax_ttm' => null,
            'tax_ttm' => null, 'da_ttm' => null, 'eps_diluted_ttm' => null, 'dividends_per_share_ttm' => null,
            'total_debt' => null, 'cash' => null, 'equity' => null, 'shares_outstanding' => null,
            'inputs' => [],
        ];

        $anchorFacts = $facts->facts('us-gaap:EarningsPerShareDiluted', 'USD/shares');
        $anchor = $this->ttm->anchor($anchorFacts);
        if ($anchor === null) {
            $anchorFacts = $facts->facts('us-gaap:NetIncomeLoss');
            $anchor = $this->ttm->anchor($anchorFacts);
        }
        if ($anchor === null) {
            return $empty;
        }

        $source = $this->anchorFiling($anchorFacts, $anchor);
        $inputs = [];
        $row = array_merge($empty, [
            'period_end' => $anchor,
            'latest_accession' => $source['accn'],
            'latest_form' => $source['form'],
            'latest_filed_at' => $source['filed'] !== '' ? $source['filed'] : null,
        ]);

        // Revenue (financials: net of interest expense first) and its prior year.
        $chain = $isFinancial ? array_merge(['us-gaap:RevenuesNetOfInterestExpense'], array_diff(self::REVENUE, ['us-gaap:RevenuesNetOfInterestExpense'])) : self::REVENUE;
        [$revenueConcept, $revenue] = $this->firstTtm($facts, $chain, $anchor);
        if ($revenue !== null) {
            $row['revenue_ttm'] = $revenue['value'];
            $inputs['revenue_ttm'] = self::provenance($revenueConcept, $revenue);
            $prior = $this->ttm->priorTtm($facts->facts($revenueConcept), $anchor);
            if ($prior !== null) {
                $row['revenue_prior_ttm'] = $prior['value'];
                $row['prior_period_end'] = $prior['end'];
                $inputs['revenue_prior_ttm'] = self::provenance($revenueConcept, $prior);
            }
        }

        // Pre-tax income and tax (tax rate for ROIC; EBIT fallback).
        [$pretaxConcept, $pretax] = $this->firstTtm($facts, self::PRETAX, $anchor);
        if ($pretax !== null) {
            $row['pretax_ttm'] = $pretax['value'];
            $inputs['pretax_ttm'] = self::provenance($pretaxConcept, $pretax);
        }
        [$taxConcept, $tax] = $this->firstTtm($facts, ['us-gaap:IncomeTaxExpenseBenefit'], $anchor);
        if ($tax !== null) {
            $row['tax_ttm'] = $tax['value'];
            $inputs['tax_ttm'] = self::provenance($taxConcept, $tax);
        }

        // EBIT: operating income, else pre-tax income + interest expense.
        [$ebitConcept, $ebit] = $this->firstTtm($facts, ['us-gaap:OperatingIncomeLoss'], $anchor);
        if ($ebit !== null) {
            $row['ebit_ttm'] = $ebit['value'];
            $inputs['ebit_ttm'] = self::provenance($ebitConcept, $ebit);
        } elseif ($pretax !== null) {
            [$interestConcept, $interest] = $this->firstTtm($facts, self::INTEREST, $anchor);
            if ($interest !== null) {
                $row['ebit_ttm'] = $pretax['value'] + $interest['value'];
                $inputs['ebit_ttm'] = self::provenance($pretaxConcept.'+'.$interestConcept, $pretax, 'pretax+interest');
            }
        }

        // D&A: combined concepts, else Depreciation + AmortizationOfIntangibleAssets (0 if absent).
        [$daConcept, $da] = $this->firstTtm($facts, self::DA, $anchor);
        if ($da !== null) {
            $row['da_ttm'] = $da['value'];
            $inputs['da_ttm'] = self::provenance($daConcept, $da);
        } else {
            $depreciation = $this->ttm->ttm($facts->facts('us-gaap:Depreciation'), $anchor);
            if ($depreciation !== null) {
                $amortization = $this->ttm->ttm($facts->facts('us-gaap:AmortizationOfIntangibleAssets'), $anchor);
                $row['da_ttm'] = $depreciation['value'] + ($amortization['value'] ?? 0.0);
                $inputs['da_ttm'] = self::provenance(
                    $amortization === null ? 'us-gaap:Depreciation' : 'us-gaap:Depreciation+us-gaap:AmortizationOfIntangibleAssets',
                    $depreciation,
                    'depreciation+amortization',
                );
            }
        }

        // Diluted EPS (TTM by the same formula: an approximation, share counts change).
        [$epsConcept, $eps] = $this->firstTtm($facts, self::EPS, $anchor, 'USD/shares');
        if ($eps !== null) {
            $row['eps_diluted_ttm'] = $eps['value'];
            $inputs['eps_diluted_ttm'] = self::provenance($epsConcept, $eps);
        }

        // Balance sheet at the newest StockholdersEquity date.
        $equityFact = $this->ttm->latestInstant($facts->facts('us-gaap:StockholdersEquity'));
        if ($equityFact !== null) {
            $date = $equityFact['end'];
            $row['balance_sheet_date'] = $date;
            $row['equity'] = $equityFact['val'];
            $inputs['equity'] = self::provenance('us-gaap:StockholdersEquity', $equityFact, 'instant');

            [$debt, $debtSource] = $this->debt($facts, $date);
            if ($debt !== null) {
                $row['total_debt'] = $debt;
                $inputs['total_debt'] = ['concept' => $debtSource, 'end' => $date, 'method' => 'instant'];
            }
            foreach (self::CASH as $concept) {
                $cash = $this->ttm->instantAt($facts->facts($concept), $date);
                if ($cash !== null) {
                    $row['cash'] = $cash['val'];
                    $inputs['cash'] = self::provenance($concept, $cash, 'instant');
                    break;
                }
            }
        }

        // Shares outstanding.
        $shares = $this->shares($facts);
        if ($shares !== null) {
            $row['shares_outstanding'] = $shares['value'];
            $row['shares_as_of'] = $shares['end'];
            $inputs['shares_outstanding'] = ['concept' => $shares['concept'], 'end' => $shares['end'], 'accession' => $shares['accn'], 'method' => $shares['method']];
        }

        // Dividends per share: declared, cash paid, else dividends paid / shares.
        [$dpsConcept, $dps] = $this->firstTtm($facts, self::DPS, $anchor, 'USD/shares');
        if ($dps !== null) {
            $row['dividends_per_share_ttm'] = $dps['value'];
            $inputs['dividends_per_share_ttm'] = self::provenance($dpsConcept, $dps);
        } elseif ($shares !== null && $shares['value'] > 0) {
            [$paidConcept, $paid] = $this->firstTtm($facts, self::DIVIDENDS_PAID, $anchor);
            if ($paid !== null) {
                $row['dividends_per_share_ttm'] = $paid['value'] / $shares['value'];
                $inputs['dividends_per_share_ttm'] = self::provenance($paidConcept, $paid, 'dividends_paid/shares');
            }
        }

        $complete = $row['revenue_ttm'] !== null && $row['eps_diluted_ttm'] !== null
            && $row['shares_outstanding'] !== null && $row['equity'] !== null
            && ($isFinancial || ($row['ebit_ttm'] !== null && $row['da_ttm'] !== null && $row['total_debt'] !== null && $row['cash'] !== null));
        $row['status'] = $complete ? self::STATUS_OK : self::STATUS_PARTIAL;
        $row['inputs'] = $inputs;

        return $row;
    }

    /**
     * First concept of a chain with a TTM ending at the anchor.
     *
     * @param  list<string>  $chain
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function firstTtm(CompanyFactsReader $facts, array $chain, string $anchor, string $unit = 'USD'): array
    {
        foreach ($chain as $concept) {
            $value = $this->ttm->ttm($facts->facts($concept, $unit), $anchor);
            if ($value !== null) {
                return [$concept, $value];
            }
        }

        return [null, null];
    }

    /**
     * The filing that supplied the anchor period (latest filed fact ending at it).
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{accn: string|null, form: string|null, filed: string}
     */
    private function anchorFiling(array $facts, string $anchor): array
    {
        $best = null;
        foreach (TtmCalculator::durations($facts) as $fact) {
            if ($fact['end'] === $anchor && ($best === null || strcmp($fact['filed'], $best['filed']) > 0)) {
                $best = $fact;
            }
        }

        return [
            'accn' => $best['accn'] ?? null ?: null,
            'form' => $best['form'] ?? null,
            'filed' => (string) ($best['filed'] ?? ''),
        ];
    }

    /**
     * Total debt at the balance-sheet date (leases excluded): non-current +
     * current. Null when no long-term debt concept exists.
     *
     * @return array{0: float|null, 1: string}
     */
    private function debt(CompanyFactsReader $facts, string $date): array
    {
        $at = fn (string $concept): ?float => $this->ttm->instantAt($facts->facts($concept), $date)['val'] ?? null;

        $parts = [];
        $noncurrent = $at('us-gaap:LongTermDebtNoncurrent');
        if ($noncurrent !== null) {
            $parts[] = 'LongTermDebtNoncurrent';
        } else {
            $noncurrent = $at('us-gaap:LongTermDebtAndCapitalLeaseObligations');
            if ($noncurrent !== null) {
                $parts[] = 'LongTermDebtAndCapitalLeaseObligations';
            } else {
                $total = $at('us-gaap:LongTermDebt');
                if ($total !== null) {
                    $noncurrent = $total - ($at('us-gaap:LongTermDebtCurrent') ?? 0.0);
                    $parts[] = 'LongTermDebt-LongTermDebtCurrent';
                }
            }
        }
        if ($noncurrent === null) {
            return [null, ''];
        }

        $current = $at('us-gaap:DebtCurrent');
        if ($current !== null) {
            $parts[] = 'DebtCurrent';
        } else {
            $current = 0.0;
            foreach (['LongTermDebtCurrent', 'LongTermDebtAndCapitalLeaseObligationsCurrent'] as $concept) {
                $value = $at('us-gaap:'.$concept);
                if ($value !== null) {
                    $current += $value;
                    $parts[] = $concept;
                    break;
                }
            }
            foreach (['CommercialPaper', 'ShortTermBorrowings'] as $concept) {
                $value = $at('us-gaap:'.$concept);
                if ($value !== null) {
                    $current += $value;
                    $parts[] = $concept;
                }
            }
        }

        return [$noncurrent + $current, implode('+', $parts)];
    }

    /**
     * Shares outstanding: cover-page `dei` count (classes of one filing summed),
     * else balance-sheet common shares, else the latest diluted weighted average.
     *
     * @return array{value: float, end: string, accn: string, concept: string, method: string}|null
     */
    private function shares(CompanyFactsReader $facts): ?array
    {
        $cover = array_values(array_filter(
            $facts->facts('dei:EntityCommonStockSharesOutstanding', 'shares'),
            fn (array $fact): bool => ($fact['start'] ?? null) === null,
        ));
        if ($cover !== []) {
            $end = max(array_column($cover, 'end'));
            $atEnd = array_values(array_filter($cover, fn (array $fact): bool => $fact['end'] === $end));
            usort($atEnd, fn (array $a, array $b): int => strcmp($b['filed'], $a['filed']));
            $accn = $atEnd[0]['accn'];
            $sum = array_sum(array_map(fn (array $fact): float => $fact['val'], array_filter($atEnd, fn (array $fact): bool => $fact['accn'] === $accn)));
            if ($sum > 0) {
                return ['value' => $sum, 'end' => $end, 'accn' => $accn, 'concept' => 'dei:EntityCommonStockSharesOutstanding', 'method' => 'cover'];
            }
        }

        $balance = $this->ttm->latestInstant($facts->facts('us-gaap:CommonStockSharesOutstanding', 'shares'));
        if ($balance !== null && $balance['val'] > 0) {
            return ['value' => $balance['val'], 'end' => $balance['end'], 'accn' => $balance['accn'], 'concept' => 'us-gaap:CommonStockSharesOutstanding', 'method' => 'instant'];
        }

        $weighted = $this->ttm->latestQuarter($facts->facts('us-gaap:WeightedAverageNumberOfDilutedSharesOutstanding', 'shares'));
        if ($weighted !== null && $weighted['val'] > 0) {
            return ['value' => $weighted['val'], 'end' => $weighted['end'], 'accn' => $weighted['accn'], 'concept' => 'us-gaap:WeightedAverageNumberOfDilutedSharesOutstanding', 'method' => 'weighted_quarter'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    private static function provenance(?string $concept, array $fact, ?string $method = null): array
    {
        return array_filter([
            'concept' => $concept,
            'start' => $fact['start'] ?? null,
            'end' => $fact['end'] ?? null,
            'form' => $fact['form'] ?? null,
            'accession' => $fact['accn'] ?? null,
            'method' => $method ?? ($fact['method'] ?? null),
        ], fn ($value): bool => $value !== null);
    }
}
