<?php

namespace App\Http\Controllers;

use App\Models\ChartPattern;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public, read-only screener over a Universe's Instruments.
 *
 * The route is anonymous by design: browsing the Screener requires no session
 * (`docs/user-and-access-model.md`) and the data is system-owned public market
 * data, so there is no ownership or authorization branch. Filtering and
 * ordering happen server-side over the ~503 universe members (bounded), so the
 * SPA never re-implements ranking.
 *
 * `limit` is the only silently clamped param (it cannot change the result set);
 * every other param can change which rows or the order, so a bad value is a
 * `422` (see `CONSTRAINTS.md` -> Public API).
 */
class ScreenerController extends Controller
{
    /**
     * Default candidate count when `?limit` is absent.
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * Hard cap on the candidate count, whatever `?limit` says.
     */
    private const MAX_LIMIT = 500;

    /**
     * The fixed signal vocabulary owned by `signals-detect` / `SignalFactory`.
     */
    private const SIGNAL_TYPES = [
        'golden_cross',
        'death_cross',
        'ma_alignment_bullish',
        'ma_alignment_bearish',
        'pivot_breakout_rvol',
        'rsi_overbought',
        'rsi_oversold',
        'macd_bullish_cross',
        'macd_bearish_cross',
    ];

    /**
     * Pattern statuses accepted by `?pattern_status` (`any` = both).
     */
    private const PATTERN_STATUSES = ['any', 'forming', 'confirmed'];

    /**
     * The supported sort orders (all with a ticker-ASC tie-break, nulls last).
     */
    private const SORTS = [
        'rvol_desc',
        'rsi_desc',
        'rsi_asc',
        'change_desc',
        'change_asc',
        'signal_count_desc',
    ];

    /**
     * Return the ranked Candidate list for a Universe.
     */
    public function index(Request $request): JsonResponse
    {
        $universe = $this->resolveUniverse($request);

        if ($universe === null) {
            return response()->json(['message' => __('messages.universe_not_found')], 404);
        }

        $filters = $this->resolveFilters($request);
        $limit = $this->resolveLimit($request);

        // A Candidate must have both a latest bar and a latest snapshot: without
        // either, the candidate fields cannot be produced, even with no filter.
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
            ->all();

        $this->sortCandidates($candidates, $filters['sort']);

        $total = count($candidates);
        $page = array_slice($candidates, 0, $limit);

        return response()->json([
            'universe' => [
                'slug' => $universe->slug,
                'name' => $universe->name,
            ],
            'sort' => $filters['sort'],
            'candidates' => $page,
            'meta' => [
                'limit' => $limit,
                'returned' => count($page),
                'total' => $total,
            ],
        ]);
    }

    /**
     * Resolve the `universe` slug, defaulting to `config('ingestion.universe')`.
     *
     * An unknown slug is a bad request (JSON `404`), not an empty list; a valid
     * universe that matches nothing is a `200` with an empty candidate list.
     */
    private function resolveUniverse(Request $request): ?Universe
    {
        $slug = $request->query('universe');

        if ($slug === null || $slug === '') {
            $slug = (string) config('ingestion.universe');
        }

        if (! is_string($slug)) {
            return null;
        }

        return Universe::query()->where('slug', $slug)->first();
    }

    /**
     * Resolve and validate the filter/sort params.
     *
     * `limit` is deliberately absent here: it cannot change the result set, so it
     * is clamped rather than validated. Every other param can change which rows
     * are returned or their order, so a bad value is a `422`.
     *
     * @return array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string}
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'signal' => $this->resolveSignalTypes($request),
            'rsi_min' => $this->numericParam($request, 'rsi_min'),
            'rsi_max' => $this->numericParam($request, 'rsi_max'),
            'min_rvol' => $this->numericParam($request, 'min_rvol'),
            'price_above_sma200' => $this->resolveBooleanFlag($request, 'price_above_sma200'),
            'ma_cross' => $this->resolveMaCross($request),
            'pattern' => $this->resolvePatternTypes($request),
            'pattern_status' => $this->resolvePatternStatus($request),
            'sort' => $this->resolveSort($request),
        ];
    }

    /**
     * Resolve `?pattern` (comma string and/or array, OR-combined) like `?signal`.
     *
     * @return list<string>
     */
    private function resolvePatternTypes(Request $request): array
    {
        $raw = $request->query('pattern');

        if ($raw === null) {
            return [];
        }

        $types = [];

        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'pattern' => [__('messages.screener.pattern_invalid')],
                ]);
            }

            $type = trim($value);

            if ($type === '') {
                continue;
            }

            if (! in_array($type, ChartPattern::TYPES, true)) {
                throw ValidationException::withMessages([
                    'pattern' => [__('messages.screener.pattern_invalid')],
                ]);
            }

            $types[$type] = true;
        }

        return array_keys($types);
    }

    /**
     * Resolve `?pattern_status` (`any` by default); it only narrows `?pattern`.
     */
    private function resolvePatternStatus(Request $request): string
    {
        $value = $request->query('pattern_status');

        if ($value === null || $value === '') {
            return 'any';
        }

        if (! is_string($value) || ! in_array($value, self::PATTERN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'pattern_status' => [__('messages.screener.pattern_status_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `?signal` from the comma-string and/or array form.
     *
     * Multiple types combine with OR. An unknown type is a `422`; an absent or
     * empty value means the filter is not applied.
     *
     * @return list<string>
     */
    private function resolveSignalTypes(Request $request): array
    {
        $raw = $request->query('signal');

        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $types = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'signal' => [__('messages.screener.signal_unknown_types')],
                ]);
            }

            $type = trim($value);

            if ($type === '') {
                continue;
            }

            if (! in_array($type, self::SIGNAL_TYPES, true)) {
                throw ValidationException::withMessages([
                    'signal' => [__('messages.screener.signal_invalid')],
                ]);
            }

            $types[$type] = true;
        }

        return array_keys($types);
    }

    /**
     * Resolve a nullable numeric query param; a bad value is a `422`.
     *
     * An absent or empty value means the filter is not applied. An explicit
     * `rsi_min` greater than `rsi_max` is valid and simply matches nothing.
     */
    private function numericParam(Request $request, string $key): ?float
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.parameter_number', ['parameter' => $key])],
            ]);
        }

        return (float) $value;
    }

    /**
     * Resolve a boolean flag; an unrecognized value is a `422`.
     *
     * Absent/empty means the filter is off.
     */
    private function resolveBooleanFlag(Request $request, string $key): bool
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return false;
        }

        $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($flag === null) {
            throw ValidationException::withMessages([
                $key => [__('messages.screener.parameter_boolean', ['parameter' => $key])],
            ]);
        }

        return $flag;
    }

    /**
     * Resolve `?ma_cross` to `bullish`/`bearish`; anything else is a `422`.
     */
    private function resolveMaCross(Request $request): ?string
    {
        $value = $request->query('ma_cross');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, ['bullish', 'bearish'], true)) {
            throw ValidationException::withMessages([
                'ma_cross' => [__('messages.screener.ma_cross_invalid')],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `?sort` to a supported order; anything else is a `422`.
     */
    private function resolveSort(Request $request): string
    {
        $value = $request->query('sort');

        if ($value === null || $value === '') {
            return 'rvol_desc';
        }

        if (! is_string($value) || ! in_array($value, self::SORTS, true)) {
            throw ValidationException::withMessages([
                'sort' => [__('messages.screener.sort_invalid', ['values' => implode(', ', self::SORTS)])],
            ]);
        }

        return $value;
    }

    /**
     * Resolve `?limit` to a bounded integer.
     *
     * A non-numeric value falls back to the default and the value is clamped
     * silently to `1..500`; it is never a validation error.
     */
    private function resolveLimit(Request $request): int
    {
        if (! is_numeric($request->query('limit'))) {
            return self::DEFAULT_LIMIT;
        }

        return min(max((int) $request->query('limit'), 1), self::MAX_LIMIT);
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
     * @param  array{signal: list<string>, rsi_min: ?float, rsi_max: ?float, min_rvol: ?float, price_above_sma200: bool, ma_cross: ?string, pattern: list<string>, pattern_status: string, sort: string}  $filters
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
