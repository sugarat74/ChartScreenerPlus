<?php

namespace App\Services\Fundamentals;

use App\Models\CompanyFundamental;

/**
 * The `fundamentals-v1` metrics, computed on read from the stored inputs and
 * our latest close (spec decision 6). Pure; used by the API payload and by the
 * Company Card context builder, so both always agree.
 *
 * Every metric is `{value, period, reason}`: a null value always carries a
 * reason, a non-null value always a period (TTM / fiscal year from the filing,
 * or the price date for close-derived metrics).
 */
class FundamentalsMetrics
{
    /** Metric keys in display order. */
    public const KEYS = [
        'pe_ttm',
        'eps_diluted_ttm',
        'market_cap_millions',
        'revenue_ttm_millions',
        'revenue_growth_yoy_percent',
        'ebitda_millions',
        'ebitda_margin_percent',
        'net_debt_to_ebitda',
        'roic_percent',
        'dividend_yield_percent',
    ];

    /** Metrics derived from our close: sent to the AI provider only with the rights flag. */
    public const PRICE_DERIVED = ['pe_ttm', 'market_cap_millions', 'dividend_yield_percent'];

    public const REASONS = [
        'negative_earnings', 'negative_ebitda', 'financial_sector', 'missing_data',
        'not_reported', 'no_price', 'not_meaningful',
    ];

    /** US federal statutory rate, used when pre-tax income is not positive. */
    public const STATUTORY_TAX_RATE = 0.21;

    public const MAX_TAX_RATE = 0.5;

    /** Same sanity bounds the v1 card schema applied to metric values. */
    public const MAX_ABS_VALUE = 10_000_000;

    public const MAX_PERCENT = 1000;

    /**
     * @return array<string, array{value: float|null, period: array{kind: string, end: string, form: string|null}|null, reason: string|null}>
     */
    public static function compute(CompanyFundamental $row, ?float $close, ?string $closeDate): array
    {
        $num = fn (string $column): ?float => $row->{$column} === null ? null : (float) $row->{$column};
        $inputs = is_array($row->inputs) ? $row->inputs : [];
        $hasPrice = $close !== null && $close > 0 && $closeDate !== null;
        $price = $hasPrice ? ['kind' => 'price', 'end' => $closeDate, 'form' => null] : null;
        $period = function (string $input) use ($inputs, $row): array {
            $source = $inputs[$input] ?? [];

            return [
                'kind' => ($source['method'] ?? null) === TtmCalculator::METHOD_ANNUAL ? 'fy' : 'ttm',
                'end' => (string) ($source['end'] ?? $row->period_end?->format('Y-m-d')),
                'form' => $source['form'] ?? $row->latest_form,
            ];
        };

        $financial = (bool) $row->is_financial;
        $eps = $num('eps_diluted_ttm');
        $shares = $num('shares_outstanding');
        $revenue = $num('revenue_ttm');
        $prior = $num('revenue_prior_ttm');
        $ebit = $num('ebit_ttm');
        $da = $num('da_ttm');
        $ebitda = $ebit !== null && $da !== null ? $ebit + $da : null;
        $debt = $num('total_debt');
        $cash = $num('cash');
        $equity = $num('equity');
        $pretax = $num('pretax_ttm');
        $tax = $num('tax_ttm');
        $dps = $num('dividends_per_share_ttm');

        $m = [];

        $m['pe_ttm'] = match (true) {
            $eps === null => self::none('missing_data'),
            $eps <= 0 => self::none('negative_earnings'),
            ! $hasPrice => self::none('no_price'),
            default => self::value($close / $eps, $price, 2),
        };

        $m['eps_diluted_ttm'] = $eps === null ? self::none('missing_data') : self::value($eps, $period('eps_diluted_ttm'), 2);

        $m['market_cap_millions'] = match (true) {
            $shares === null || $shares <= 0 => self::none('missing_data'),
            ! $hasPrice => self::none('no_price'),
            default => self::value($close * $shares / 1e6, $price, 1),
        };

        $m['revenue_ttm_millions'] = $revenue === null ? self::none('missing_data') : self::value($revenue / 1e6, $period('revenue_ttm'), 1);

        $m['revenue_growth_yoy_percent'] = match (true) {
            $revenue === null || $prior === null => self::none('missing_data'),
            $prior <= 0 => self::none('not_meaningful'),
            default => self::value(($revenue / $prior - 1) * 100, $period('revenue_ttm'), 2, true),
        };

        $m['ebitda_millions'] = match (true) {
            $financial => self::none('financial_sector'),
            $ebitda === null => self::none('missing_data'),
            default => self::value($ebitda / 1e6, $period('ebit_ttm'), 1),
        };

        $m['ebitda_margin_percent'] = match (true) {
            $financial => self::none('financial_sector'),
            $ebitda === null || $revenue === null => self::none('missing_data'),
            $revenue <= 0 => self::none('not_meaningful'),
            default => self::value($ebitda / $revenue * 100, $period('ebit_ttm'), 2, true),
        };

        $m['net_debt_to_ebitda'] = match (true) {
            $financial => self::none('financial_sector'),
            $ebitda === null || $debt === null || $cash === null => self::none('missing_data'),
            $ebitda <= 0 => self::none('negative_ebitda'),
            default => self::value(($debt - $cash) / $ebitda, $period('ebit_ttm'), 2),
        };

        $invested = $debt !== null && $equity !== null && $cash !== null ? $debt + $equity - $cash : null;
        $m['roic_percent'] = match (true) {
            $financial => self::none('financial_sector'),
            $ebit === null || $invested === null => self::none('missing_data'),
            $invested <= 0 => self::none('not_meaningful'),
            default => self::value($ebit * (1 - self::taxRate($pretax, $tax)) / $invested * 100, $period('ebit_ttm'), 2, true),
        };

        $m['dividend_yield_percent'] = match (true) {
            $dps === null => self::none('not_reported'),
            ! $hasPrice => self::none('no_price'),
            default => self::value($dps / $close * 100, $price, 2, true),
        };

        return $m;
    }

    /** Effective tax rate clamped to 0..50 %, statutory 21 % when pre-tax <= 0 or unknown. */
    public static function taxRate(?float $pretax, ?float $tax): float
    {
        if ($pretax === null || $pretax <= 0 || $tax === null) {
            return self::STATUTORY_TAX_RATE;
        }

        return min(self::MAX_TAX_RATE, max(0.0, $tax / $pretax));
    }

    /** @return array{value: null, period: null, reason: string} */
    private static function none(string $reason): array
    {
        return ['value' => null, 'period' => null, 'reason' => $reason];
    }

    /**
     * @param  array{kind: string, end: string, form: string|null}|null  $period
     * @return array{value: float|null, period: array{kind: string, end: string, form: string|null}|null, reason: string|null}
     */
    private static function value(float $value, ?array $period, int $decimals, bool $percent = false): array
    {
        $bound = $percent ? self::MAX_PERCENT : self::MAX_ABS_VALUE;
        if (! is_finite($value) || abs($value) > $bound) {
            return self::none('not_meaningful');
        }

        return ['value' => round($value, $decimals), 'period' => $period, 'reason' => null];
    }
}
