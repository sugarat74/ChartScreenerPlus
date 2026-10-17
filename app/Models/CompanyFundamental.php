<?php

namespace App\Models;

use Database\Factories\CompanyFundamentalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Company Fundamentals for one Instrument: inputs computed by Chartiko from
 * the company's SEC 10-K/10-Q filings (company-fundamentals-sec), with
 * provenance in `inputs`. Metrics are derived on read by
 * `App\Services\Fundamentals\FundamentalsMetrics`.
 */
#[Fillable([
    'instrument_id', 'cik', 'sic', 'sic_description', 'is_financial', 'status', 'currency',
    'period_end', 'prior_period_end', 'balance_sheet_date', 'shares_as_of',
    'latest_accession', 'latest_form', 'latest_filed_at',
    'pending_accession', 'pending_form', 'pending_filed_at',
    'revenue_ttm', 'revenue_prior_ttm', 'ebit_ttm', 'pretax_ttm', 'tax_ttm', 'da_ttm',
    'eps_diluted_ttm', 'dividends_per_share_ttm', 'total_debt', 'cash', 'equity', 'shares_outstanding',
    'inputs', 'fetched_at', 'last_error',
])]
class CompanyFundamental extends Model
{
    /** @use HasFactory<CompanyFundamentalFactory> */
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NO_CIK = 'no_cik';

    public const STATUS_NO_DATA = 'no_data';

    public const STATUS_ERROR = 'error';

    protected function casts(): array
    {
        $decimal = array_fill_keys([
            'revenue_ttm', 'revenue_prior_ttm', 'ebit_ttm', 'pretax_ttm', 'tax_ttm', 'da_ttm',
            'eps_diluted_ttm', 'dividends_per_share_ttm', 'total_debt', 'cash', 'equity', 'shares_outstanding',
        ], 'decimal:4');

        return $decimal + [
            'is_financial' => 'boolean',
            'period_end' => 'date',
            'prior_period_end' => 'date',
            'balance_sheet_date' => 'date',
            'shares_as_of' => 'date',
            'latest_filed_at' => 'date',
            'pending_filed_at' => 'date',
            'inputs' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /** Has a computed period (ok or partial), so its figures can be shown. */
    public function hasFigures(): bool
    {
        return $this->period_end !== null;
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
