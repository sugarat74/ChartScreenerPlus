<?php

namespace App\Http\Controllers;

use App\Models\ChartPattern;
use App\Models\Universe;
use App\Services\Screener\CandidateQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request, CandidateQuery $query): JsonResponse
    {
        $universe = $this->resolveUniverse($request);

        if ($universe === null) {
            return response()->json(['message' => __('messages.universe_not_found')], 404);
        }

        $filters = $this->resolveFilters($request);
        $limit = $this->resolveLimit($request);

        // Matching, payload and ranking live in `CandidateQuery`, shared with
        // the Alerts evaluator so both always agree on the Candidate list.
        $candidates = $query->candidates($universe, $filters);

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
}
