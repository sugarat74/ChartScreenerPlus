<?php

namespace App\Services\Fundamentals;

use App\Models\CompanyFundamental;
use App\Models\Instrument;

/**
 * Read side of Company Fundamentals: the `fundamentals-v1` API payload and the
 * figures the Company Card sends to the AI provider. Database reads only;
 * nothing here calls the SEC. Returns nothing while the feature is not
 * configured (disabled or no valid `SEC_USER_AGENT`).
 */
class CompanyFundamentalsService
{
    public function __construct(private readonly SecEdgarClient $client) {}

    public function current(Instrument $instrument): ?CompanyFundamental
    {
        if (! $this->client->isConfigured()) {
            return null;
        }

        return CompanyFundamental::query()->where('instrument_id', $instrument->id)->first();
    }

    /** Accession of the filing the current figures come from (card freshness). */
    public function accession(Instrument $instrument): ?string
    {
        $row = $this->current($instrument);

        return $row !== null && $row->hasFigures() ? $row->latest_accession : null;
    }

    /** @return array<string, mixed>|null */
    public function payload(Instrument $instrument): ?array
    {
        $row = $this->current($instrument);
        if ($row === null) {
            return null;
        }
        [$close, $date] = $this->latestClose($instrument);

        return [
            'schema_version' => (string) config('fundamentals.schema_version'),
            'source' => 'sec_edgar',
            'currency' => $row->currency,
            'is_financial' => (bool) $row->is_financial,
            'period_end' => $row->period_end?->format('Y-m-d'),
            'filing' => $row->hasFigures() && $row->latest_form !== null ? [
                'form' => $row->latest_form,
                'filed_at' => $row->latest_filed_at?->format('Y-m-d'),
                'accession' => $row->latest_accession,
            ] : null,
            'pending_filing' => $row->pending_accession !== null ? [
                'form' => $row->pending_form,
                'filed_at' => $row->pending_filed_at?->format('Y-m-d'),
            ] : null,
            'price' => $close !== null ? ['close' => round($close, 4), 'date' => $date] : null,
            'fetched_at' => $row->fetched_at?->copy()->utc()->toIso8601ZuluString(),
            'metrics' => FundamentalsMetrics::compute($row, $close, $date),
        ];
    }

    /**
     * Figures for the AI provider (spec decision 8): the SEC-derived metrics
     * whenever a row with figures exists; metrics derived from our close (and
     * the close itself) only when `$includePrice` (the market-data rights flag).
     *
     * @return array<string, mixed>|null
     */
    public function context(Instrument $instrument, bool $includePrice): ?array
    {
        $row = $this->current($instrument);
        if ($row === null || ! $row->hasFigures()) {
            return null;
        }
        [$close, $date] = $includePrice ? $this->latestClose($instrument) : [null, null];
        $metrics = FundamentalsMetrics::compute($row, $close, $date);

        $out = [];
        foreach (FundamentalsMetrics::KEYS as $key) {
            if (! $includePrice && in_array($key, FundamentalsMetrics::PRICE_DERIVED, true)) {
                continue;
            }
            $metric = $metrics[$key];
            $out[$key] = $metric['value'] !== null
                ? ['value' => $metric['value'], 'period' => self::periodLabel($metric['period'])]
                : ['value' => null, 'reason' => $metric['reason']];
        }

        $context = [
            'source' => 'SEC EDGAR filings (10-K/10-Q), computed by Chartiko',
            'currency' => $row->currency,
            'period_end' => $row->period_end?->format('Y-m-d'),
            'filing' => ['form' => $row->latest_form, 'filed_at' => $row->latest_filed_at?->format('Y-m-d')],
            'is_financial' => (bool) $row->is_financial,
            'units' => 'amounts in millions of USD; *_percent in percent; ratios in times; eps in USD per share',
            'metrics' => $out,
        ];
        if ($includePrice && $close !== null) {
            $context['price'] = ['close' => round($close, 4), 'date' => $date];
        }

        return $context;
    }

    /** @param array{kind: string, end: string, form: string|null}|null $period */
    private static function periodLabel(?array $period): ?string
    {
        if ($period === null) {
            return null;
        }

        return match ($period['kind']) {
            'fy' => "fiscal year to {$period['end']}".($period['form'] ? " ({$period['form']})" : ''),
            'price' => "close of {$period['end']}",
            default => "TTM to {$period['end']}".($period['form'] ? " ({$period['form']})" : ''),
        };
    }

    /** @return array{0: float|null, 1: string|null} */
    private function latestClose(Instrument $instrument): array
    {
        $bar = $instrument->dailyBars()->orderByDesc('date')->first();

        return $bar === null ? [null, null] : [(float) $bar->close, $bar->date->format('Y-m-d')];
    }
}
