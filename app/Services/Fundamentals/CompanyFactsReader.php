<?php

namespace App\Services\Fundamentals;

/**
 * Reduces a decoded companyfacts document (3-8 MB) to the whitelisted
 * concepts right after decoding, keeping only facts from 10-K/10-Q (and
 * their amendments). It also remembers every accession number present in the
 * document, so the refresher can tell when a newer filing listed in the
 * submissions is not yet in companyfacts (the "pending filing" lag).
 */
class CompanyFactsReader
{
    public const FORMS = ['10-K', '10-K/A', '10-Q', '10-Q/A'];

    /** `taxonomy:Concept` names used by FundamentalsExtractor. */
    public const CONCEPTS = [
        'us-gaap:Revenues',
        'us-gaap:RevenueFromContractWithCustomerExcludingAssessedTax',
        'us-gaap:RevenueFromContractWithCustomerIncludingAssessedTax',
        'us-gaap:SalesRevenueNet',
        'us-gaap:RevenuesNetOfInterestExpense',
        'us-gaap:OperatingIncomeLoss',
        'us-gaap:IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest',
        'us-gaap:IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments',
        'us-gaap:InterestExpense',
        'us-gaap:InterestExpenseNonoperating',
        'us-gaap:InterestExpenseDebt',
        'us-gaap:DepreciationDepletionAndAmortization',
        'us-gaap:DepreciationAndAmortization',
        'us-gaap:DepreciationAmortizationAndAccretionNet',
        'us-gaap:Depreciation',
        'us-gaap:AmortizationOfIntangibleAssets',
        'us-gaap:EarningsPerShareDiluted',
        'us-gaap:EarningsPerShareBasicAndDiluted',
        'us-gaap:NetIncomeLoss',
        'us-gaap:IncomeTaxExpenseBenefit',
        'us-gaap:LongTermDebtNoncurrent',
        'us-gaap:LongTermDebtAndCapitalLeaseObligations',
        'us-gaap:LongTermDebt',
        'us-gaap:LongTermDebtCurrent',
        'us-gaap:DebtCurrent',
        'us-gaap:LongTermDebtAndCapitalLeaseObligationsCurrent',
        'us-gaap:CommercialPaper',
        'us-gaap:ShortTermBorrowings',
        'us-gaap:CashAndCashEquivalentsAtCarryingValue',
        'us-gaap:CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents',
        'us-gaap:StockholdersEquity',
        'us-gaap:CommonStockSharesOutstanding',
        'us-gaap:WeightedAverageNumberOfDilutedSharesOutstanding',
        'us-gaap:CommonStockDividendsPerShareDeclared',
        'us-gaap:CommonStockDividendsPerShareCashPaid',
        'us-gaap:PaymentsOfDividendsCommonStock',
        'us-gaap:PaymentsOfDividends',
        'dei:EntityCommonStockSharesOutstanding',
    ];

    /**
     * @param  array<string, array<string, list<array<string, mixed>>>>  $facts  concept => unit => facts
     * @param  array<string, true>  $accessions
     */
    private function __construct(private readonly array $facts, private readonly array $accessions) {}

    /** @param array<mixed> $document */
    public static function fromArray(array $document): self
    {
        $wanted = array_fill_keys(self::CONCEPTS, true);
        $forms = array_fill_keys(self::FORMS, true);
        $facts = [];
        $accessions = [];

        foreach ((array) ($document['facts'] ?? []) as $taxonomy => $concepts) {
            if (! is_array($concepts)) {
                continue;
            }
            foreach ($concepts as $concept => $body) {
                $name = $taxonomy.':'.$concept;
                $keep = isset($wanted[$name]);
                foreach ((array) ($body['units'] ?? []) as $unit => $list) {
                    if (! is_array($list)) {
                        continue;
                    }
                    foreach ($list as $fact) {
                        if (! is_array($fact)) {
                            continue;
                        }
                        if (isset($fact['accn']) && is_string($fact['accn'])) {
                            $accessions[$fact['accn']] = true;
                        }
                        if (! $keep || ! isset($forms[$fact['form'] ?? '']) || ! self::valid($fact)) {
                            continue;
                        }
                        $facts[$name][(string) $unit][] = [
                            'start' => $fact['start'] ?? null,
                            'end' => $fact['end'],
                            'val' => (float) $fact['val'],
                            'accn' => (string) ($fact['accn'] ?? ''),
                            'form' => (string) $fact['form'],
                            'filed' => (string) ($fact['filed'] ?? ''),
                        ];
                    }
                }
            }
        }

        return new self($facts, $accessions);
    }

    /**
     * Facts of one concept and unit (`USD`, `USD/shares`, `shares`).
     *
     * @return list<array{start: string|null, end: string, val: float, accn: string, form: string, filed: string}>
     */
    public function facts(string $concept, string $unit = 'USD'): array
    {
        return $this->facts[$concept][$unit] ?? [];
    }

    public function hasAccession(string $accession): bool
    {
        return isset($this->accessions[$accession]);
    }

    public function isEmpty(): bool
    {
        return $this->facts === [];
    }

    /** @param array<mixed> $fact */
    private static function valid(array $fact): bool
    {
        $date = '/^\d{4}-\d{2}-\d{2}$/';

        return isset($fact['end'], $fact['val'])
            && is_string($fact['end']) && preg_match($date, $fact['end']) === 1
            && is_numeric($fact['val'])
            && (! isset($fact['start']) || (is_string($fact['start']) && preg_match($date, $fact['start']) === 1));
    }
}
