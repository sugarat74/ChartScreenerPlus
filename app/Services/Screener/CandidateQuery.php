<?php

namespace App\Services\Screener;

use App\Models\ChartPattern;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ranked Candidate list for a Universe and a set of already-validated
 * filters (extracted from `ScreenerController` for `alerts-engine`).
 *
 * `$filters` uses the Screener API param names, i.e. the same shape as a
 * Saved Screener's canonical `filters` object: `signal`, `rsi_min`,
 * `rsi_max`, `min_rvol`, `price_above_sma200`, `ma_cross`, `pattern`,
 * `pattern_status` and `sort`. The HTTP controller validates query params into
 * that shape; `fromSaved()` normalizes a stored definition into it.
 *
 * @phpstan-type Filters array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string}
 */
class CandidateQuery
{
    /**
     * Every matching Candidate payload, ranked (the caller applies any limit).
     *
     * A Candidate must have both a latest bar and a latest snapshot: without
     * either, the candidate fields cannot be produced, even with no filter.
     *
     * @param  Filters  $filters
     * @return list<array<string, mixed>>
     */
    public function candidates(Universe $universe, array $filters): array
    {
        $members = $universe->instruments()
            ->with(['latestBar', 'latestSnapshot', 'signals', 'chartPatterns'])
            ->get()
            ->filter(fn (Instrument $instrument): bool => $instrument->latestBar !== null
                && $instrument->latestSnapshot !== null)
            ->values();

        $previousClose = $this->previousCloseByInstrument($members->pluck('id')->all());

        $candidates = $members
            ->filter(fn (Instrument $instrument): bool => $this->matchesFilters($instrument, $filters))
            ->map(fn (Instrument $instrument): array => $this->candidatePayload(
                $instrument,
                $previousClose->get($instrument->id),
            ))
            ->values()
            ->all();

        $this->sortCandidates($candidates, $filters['sort']);

        return $candidates;
    }

    /**
     * Normalize a stored Saved Screener definition into validated filters.
     *
     * Lenient by design (stored rows were validated on save): unknown values
     * fall back to "filter off", so an old or odd row can never throw.
     *
     * @param  array<string, mixed>  $stored
     * @return Filters
     */
    public static function fromSaved(array $stored): array
    {
        $number = fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $list = fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        $patterns = array_values(array_intersect(ChartPattern::TYPES, $list($stored['pattern'] ?? [])));

        return [
            'signal' => $list($stored['signal'] ?? []),
            'rsi_min' => $number($stored['rsi_min'] ?? null),
            'rsi_max' => $number($stored['rsi_max'] ?? null),
            'min_rvol' => $number($stored['min_rvol'] ?? null),
            'price_above_sma200' => ($stored['price_above_sma200'] ?? false) === true,
            'ma_cross' => in_array($stored['ma_cross'] ?? null, ['bullish', 'bearish'], true) ? $stored['ma_cross'] : null,
            'pattern' => $patterns,
            'pattern_status' => $patterns !== [] && in_array($stored['pattern_status'] ?? null, ['forming', 'confirmed'], true)
                ? $stored['pattern_status']
                : 'any',
            'sort' => is_string($stored['sort'] ?? null) ? $stored['sort'] : 'rvol_desc',
        ];
    }

    /**
     * Build the `instrument_id => previous close` map in one bounded query.
     *
     * For every instrument it selects the bar whose date is the max date
     * strictly below that instrument's own max date (a correlated subquery, so
     * the query count stays constant regardless of the universe size). An
     * instrument with fewer than two bars is simply absent from the map.
     *
     * @param  list<int>  $instrumentIds
     * @return Collection<int, float>
     */
    private function previousCloseByInstrument(array $instrumentIds): Collection
    {
        if ($instrumentIds === []) {
            return collect();
        }

        return DB::table('daily_bars as b')
            ->whereIn('b.instrument_id', $instrumentIds)
            ->whereRaw(
                'b.date = (select max(b2.date) from daily_bars as b2 where b2.instrument_id = b.instrument_id'
                .' and b2.date < (select max(b3.date) from daily_bars as b3 where b3.instrument_id = b.instrument_id))'
            )
            ->pluck('close', 'instrument_id')
            ->map(fn ($close): float => (float) $close);
    }

    /**
     * Whether an instrument satisfies every active filter (AND).
     *
     * A `null` indicator (insufficient history) never satisfies a numeric or
     * derived filter, so such an instrument is excluded whenever that filter is
     * applied.
     *
     * @param  Filters  $filters
     */
    private function matchesFilters(Instrument $instrument, array $filters): bool
    {
        $bar = $instrument->latestBar;
        $snapshot = $instrument->latestSnapshot;

        if ($filters['signal'] !== [] && ! $instrument->signals->contains(
            fn ($signal): bool => in_array($signal->type, $filters['signal'], true)
        )) {
            return false;
        }

        if ($filters['pattern'] !== [] && ! $instrument->chartPatterns->contains(
            fn (ChartPattern $pattern): bool => in_array($pattern->type, $filters['pattern'], true)
                && ($filters['pattern_status'] === 'any' || $pattern->status === $filters['pattern_status'])
        )) {
            return false;
        }

        if ($filters['rsi_min'] !== null && ! $this->atLeast($snapshot->rsi14, $filters['rsi_min'])) {
            return false;
        }

        if ($filters['rsi_max'] !== null && ! $this->atMost($snapshot->rsi14, $filters['rsi_max'])) {
            return false;
        }

        if ($filters['min_rvol'] !== null && ! $this->atLeast($snapshot->rvol, $filters['min_rvol'])) {
            return false;
        }

        if ($filters['price_above_sma200']) {
            $sma200 = $this->nullableFloat($snapshot->sma200);

            if ($sma200 === null || (float) $bar->close <= $sma200) {
                return false;
            }
        }

        if ($filters['ma_cross'] !== null) {
            $sma50 = $this->nullableFloat($snapshot->sma50);
            $sma200 = $this->nullableFloat($snapshot->sma200);

            if ($sma50 === null || $sma200 === null) {
                return false;
            }

            if ($filters['ma_cross'] === 'bullish' && ! ($sma50 > $sma200)) {
                return false;
            }

            if ($filters['ma_cross'] === 'bearish' && ! ($sma50 < $sma200)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a nullable `decimal:4` value is at least `$minimum`.
     */
    private function atLeast(mixed $value, float $minimum): bool
    {
        return $value !== null && (float) $value >= $minimum;
    }

    /**
     * Whether a nullable `decimal:4` value is at most `$maximum`.
     */
    private function atMost(mixed $value, float $maximum): bool
    {
        return $value !== null && (float) $value <= $maximum;
    }

    /**
     * Shape one Candidate for the API.
     *
     * @return array<string, mixed>
     */
    private function candidatePayload(Instrument $instrument, ?float $previousClose): array
    {
        $bar = $instrument->latestBar;
        $snapshot = $instrument->latestSnapshot;
        $close = (float) $bar->close;

        return [
            'ticker' => $instrument->ticker,
            'company' => $instrument->company,
            'sector' => $instrument->sector,
            'exchange' => $instrument->exchange,
            'active' => $instrument->active,
            'date' => $bar->date->format('Y-m-d'),
            'close' => $close,
            'change_percent' => $this->changePercent($close, $previousClose),
            'rvol' => $this->nullableFloat($snapshot->rvol),
            'rsi14' => $this->nullableFloat($snapshot->rsi14),
            'signals' => $instrument->signals
                ->pluck('type')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'patterns' => $instrument->chartPatterns
                ->sortBy('type')
                ->values()
                ->map(fn (ChartPattern $pattern): array => [
                    'type' => $pattern->type,
                    'status' => $pattern->status,
                    'breakout_level' => $pattern->breakout_level,
                    'end_date' => $pattern->end_date->format('Y-m-d'),
                ])
                ->all(),
        ];
    }

    /**
     * `((latest close - previous close) / previous close) * 100`, or `null`.
     */
    private function changePercent(float $close, ?float $previousClose): ?float
    {
        if ($previousClose === null || $previousClose === 0.0) {
            return null;
        }

        return (($close - $previousClose) / $previousClose) * 100;
    }

    /**
     * Rank the candidates in place by the requested order.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function sortCandidates(array &$candidates, string $sort): void
    {
        usort($candidates, function (array $a, array $b) use ($sort): int {
            $comparison = $this->compareBySort($a, $b, $sort);

            return $comparison !== 0 ? $comparison : strcmp($a['ticker'], $b['ticker']);
        });
    }

    /**
     * Compare two candidates by the sort's primary key (nulls always last).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareBySort(array $a, array $b, string $sort): int
    {
        [$key, $descending] = match ($sort) {
            'rsi_desc' => ['rsi14', true],
            'rsi_asc' => ['rsi14', false],
            'change_desc' => ['change_percent', true],
            'change_asc' => ['change_percent', false],
            'signal_count_desc' => ['signal_count', true],
            default => ['rvol', true],
        };

        $left = $key === 'signal_count' ? count($a['signals']) : $a[$key];
        $right = $key === 'signal_count' ? count($b['signals']) : $b[$key];

        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        $comparison = $left <=> $right;

        return $descending ? -$comparison : $comparison;
    }

    /**
     * Convert a nullable `decimal:4` cast value to a JSON number or `null`.
     */
    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
