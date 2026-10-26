<?php

namespace App\Services\Fundamentals;

use App\Models\CompanyFundamental;
use App\Models\Instrument;
use App\Models\Universe;
use App\Support\ListingMarket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-CIK refresh flow behind `fundamentals:refresh` (spec decision 10).
 *
 * One small submissions request per CIK (SIC + newest 10-K/10-Q); the large
 * companyfacts document is fetched only when that newest filing changed since
 * the last check, a companyfacts lag ("pending filing") is open, or `force`.
 * Instruments sharing a CIK (GOOG/GOOGL) cost one fetch and get one row each,
 * written in one transaction. A failing CIK is recorded and the run goes on;
 * only a `403` (`SecUnavailable` reason `forbidden`) stops the whole run.
 * Writes only `company_fundamentals`.
 */
class FundamentalsRefresher
{
    public const TICKERS_CACHE_KEY = 'fundamentals:sec-tickers';

    public const RESULT_UPDATED = 'updated';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_NO_DATA = 'no_data';

    public const RESULT_FAILED = 'failed';

    public function __construct(
        private readonly SecEdgarClient $client,
        private readonly FundamentalsExtractor $extractor,
    ) {}

    /**
     * Instruments to refresh: the configured universe, or the given tickers
     * (`BRK.B` and `BRK-B` both accepted).
     *
     * @param  list<string>  $tickers
     * @return Collection<int, Instrument>
     */
    public function instruments(array $tickers): Collection
    {
        if ($tickers !== []) {
            $wanted = [];
            foreach ($tickers as $ticker) {
                $ticker = strtoupper(trim($ticker));
                $wanted[] = $ticker;
                $wanted[] = str_replace('-', '.', $ticker);
            }

            return Instrument::query()->stocks()->where('market', ListingMarket::US)->whereIn('ticker', array_unique($wanted))->orderBy('ticker')->get();
        }

        $universe = Universe::query()->where('slug', (string) config('ingestion.universe'))->first();

        return $universe === null
            ? new Collection
            : $universe->instruments()->stocks()->where('instruments.market', ListingMarket::US)->where('active', true)->orderBy('ticker')->get();
    }

    /**
     * SEC ticker map (ticker -> CIK), cached for `tickers_cache_days`.
     *
     * @return array<string, string>
     */
    public function tickerMap(): array
    {
        $days = max(1, (int) config('fundamentals.tickers_cache_days'));

        return Cache::remember(self::TICKERS_CACHE_KEY, now()->addDays($days), fn (): array => $this->client->tickers());
    }

    /** SEC spelling of one of our tickers (`BRK.B` -> `BRK-B`). */
    public static function secTicker(string $ticker): string
    {
        return str_replace('.', '-', strtoupper(trim($ticker)));
    }

    /**
     * Group instruments by CIK; those without one get a `no_cik` row.
     *
     * @param  Collection<int, Instrument>  $instruments
     * @param  array<string, string>  $map
     * @return array{0: array<string, list<Instrument>>, 1: list<Instrument>}
     */
    public function group(Collection $instruments, array $map): array
    {
        $groups = [];
        $missing = [];
        foreach ($instruments as $instrument) {
            $cik = $map[self::secTicker($instrument->ticker)] ?? null;
            if ($cik === null) {
                $missing[] = $instrument;

                continue;
            }
            $groups[$cik][] = $instrument;
        }

        return [$groups, $missing];
    }

    /** Record that the SEC ticker map has no CIK for this Instrument. */
    public function markNoCik(Instrument $instrument): void
    {
        CompanyFundamental::query()->updateOrCreate(
            ['instrument_id' => $instrument->id],
            self::emptyValues() + ['cik' => null, 'status' => CompanyFundamental::STATUS_NO_CIK, 'fetched_at' => now(), 'last_error' => null],
        );
    }

    /**
     * Refresh every Instrument of one CIK.
     *
     * @param  list<Instrument>  $instruments
     * @return string One of the RESULT_* constants.
     *
     * @throws SecUnavailable with reason `forbidden` (the run must stop).
     */
    public function refresh(string $cik, array $instruments, bool $force): string
    {
        try {
            return $this->refreshCik($cik, $instruments, $force);
        } catch (SecUnavailable $exception) {
            if ($exception->reason === 'forbidden') {
                throw $exception;
            }
            $this->recordFailure($cik, $instruments, 'sec_'.$exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Fundamentals refresh failed for one company', ['cik' => $cik, 'exception' => $exception::class]);
            $this->recordFailure($cik, $instruments, class_basename($exception));
        }

        return self::RESULT_FAILED;
    }

    /** @param list<Instrument> $instruments */
    private function refreshCik(string $cik, array $instruments, bool $force): string
    {
        try {
            $submissions = $this->client->submissions($cik);
        } catch (SecUnavailable $exception) {
            if ($exception->reason !== 'not_found') {
                throw $exception;
            }
            $this->write($instruments, self::emptyValues() + [
                'cik' => $cik, 'sic' => null, 'sic_description' => null, 'is_financial' => false, 'currency' => 'USD',
                'status' => CompanyFundamental::STATUS_NO_DATA, 'fetched_at' => now(), 'last_error' => null,
            ]);

            return self::RESULT_NO_DATA;
        }
        $sic = isset($submissions['sic']) && is_scalar($submissions['sic']) && (string) $submissions['sic'] !== '' ? mb_substr((string) $submissions['sic'], 0, 4) : null;
        $sicDescription = isset($submissions['sicDescription']) && is_string($submissions['sicDescription']) ? mb_substr($submissions['sicDescription'], 0, 120) : null;
        $isFinancial = FundamentalsExtractor::isFinancialSic($sic);
        $newest = self::newestPeriodicFiling($submissions);

        $existing = CompanyFundamental::query()
            ->whereIn('instrument_id', array_map(fn (Instrument $instrument): int => $instrument->id, $instruments))
            ->get()->keyBy('instrument_id');

        if (! $force && $newest !== null && $existing->count() === count($instruments) && $existing->every(
            fn (CompanyFundamental $row): bool => in_array($row->status, [CompanyFundamental::STATUS_OK, CompanyFundamental::STATUS_PARTIAL], true)
                && $row->pending_accession === null
                && ($row->inputs['_meta']['submissions_accession'] ?? null) === $newest['accession'],
        )) {
            return self::RESULT_UNCHANGED;
        }

        $base = [
            'cik' => $cik,
            'sic' => $sic,
            'sic_description' => $sicDescription,
            'is_financial' => $isFinancial,
            'currency' => 'USD',
            'fetched_at' => now(),
            'last_error' => null,
        ];

        try {
            $document = $this->client->companyFacts($cik);
        } catch (SecUnavailable $exception) {
            if ($exception->reason !== 'not_found') {
                throw $exception;
            }
            $this->write($instruments, self::emptyValues() + $base + ['status' => CompanyFundamental::STATUS_NO_DATA]);

            return self::RESULT_NO_DATA;
        }

        $facts = CompanyFactsReader::fromArray($document);
        unset($document);

        $values = $this->extractor->extract($facts, $isFinancial);
        $pending = $newest !== null && $values['period_end'] !== null && ! $facts->hasAccession($newest['accession']) ? $newest : null;
        $values['inputs']['_meta'] = ['submissions_accession' => $newest['accession'] ?? null];

        $this->write($instruments, $base + $values + [
            'pending_accession' => $pending['accession'] ?? null,
            'pending_form' => $pending['form'] ?? null,
            'pending_filed_at' => $pending['filed'] ?? null,
        ]);

        return $values['status'] === FundamentalsExtractor::STATUS_NO_DATA ? self::RESULT_NO_DATA : self::RESULT_UPDATED;
    }

    /**
     * Newest 10-K/10-Q (or amendment) listed in the submissions document.
     *
     * @param  array<mixed>  $submissions
     * @return array{accession: string, form: string, filed: string|null}|null
     */
    public static function newestPeriodicFiling(array $submissions): ?array
    {
        $recent = $submissions['filings']['recent'] ?? null;
        if (! is_array($recent) || ! is_array($recent['form'] ?? null) || ! is_array($recent['accessionNumber'] ?? null)) {
            return null;
        }
        $best = null;
        foreach ($recent['form'] as $i => $form) {
            if (! in_array($form, CompanyFactsReader::FORMS, true) || ! isset($recent['accessionNumber'][$i])) {
                continue;
            }
            $filed = $recent['filingDate'][$i] ?? null;
            $filed = is_string($filed) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filed) === 1 ? $filed : null;
            if ($best === null || strcmp((string) $filed, (string) $best['filed']) > 0) {
                $best = ['accession' => mb_substr((string) $recent['accessionNumber'][$i], 0, 20), 'form' => (string) $form, 'filed' => $filed];
            }
        }

        return $best;
    }

    /**
     * @param  list<Instrument>  $instruments
     * @param  array<string, mixed>  $values
     */
    private function write(array $instruments, array $values): void
    {
        DB::transaction(function () use ($instruments, $values): void {
            foreach ($instruments as $instrument) {
                CompanyFundamental::query()->updateOrCreate(['instrument_id' => $instrument->id], $values);
            }
        });
    }

    /**
     * Keep the last good figures; only record the error (or create an
     * `error` row when there is nothing yet).
     *
     * @param  list<Instrument>  $instruments
     */
    private function recordFailure(string $cik, array $instruments, string $error): void
    {
        $error = mb_substr($error, 0, 200);
        foreach ($instruments as $instrument) {
            $row = CompanyFundamental::query()->where('instrument_id', $instrument->id)->first();
            if ($row !== null && $row->hasFigures()) {
                $row->update(['last_error' => $error]);

                continue;
            }
            CompanyFundamental::query()->updateOrCreate(
                ['instrument_id' => $instrument->id],
                self::emptyValues() + ['cik' => $cik, 'status' => CompanyFundamental::STATUS_ERROR, 'fetched_at' => now(), 'last_error' => $error],
            );
        }
    }

    /** @return array<string, mixed> */
    private static function emptyValues(): array
    {
        return [
            'period_end' => null, 'prior_period_end' => null, 'balance_sheet_date' => null, 'shares_as_of' => null,
            'latest_accession' => null, 'latest_form' => null, 'latest_filed_at' => null,
            'pending_accession' => null, 'pending_form' => null, 'pending_filed_at' => null,
            'revenue_ttm' => null, 'revenue_prior_ttm' => null, 'ebit_ttm' => null, 'pretax_ttm' => null,
            'tax_ttm' => null, 'da_ttm' => null, 'eps_diluted_ttm' => null, 'dividends_per_share_ttm' => null,
            'total_debt' => null, 'cash' => null, 'equity' => null, 'shares_outstanding' => null,
            'inputs' => null,
        ];
    }
}
