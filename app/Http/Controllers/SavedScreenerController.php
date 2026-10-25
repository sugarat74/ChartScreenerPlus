<?php

namespace App\Http\Controllers;

use App\Models\ChartPattern;
use App\Models\SavedScreener;
use App\Services\Screener\ScreenerFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's Saved Screeners.
 *
 * Ownership is enforced server-side: every query runs through
 * `$request->user()->savedScreeners()`, no endpoint accepts a `user_id`, and a
 * row is created through the scoped relation (with `user_id` not
 * mass-assignable). Another user's id therefore simply does not match the
 * scoped relation, so a cross-user read is an empty list and a cross-user
 * delete is a `404` (no existence leak). All three routes live inside the
 * `auth:sanctum` group, so a guest gets `401`.
 *
 * The stored `filters` JSON is the canonical twenty-key definition in API param
 * names (the same vocabulary as `GET /api/screener`), including `sort`, so
 * applying a Screener restores the exact filters and ranking. Create validation
 * whitelists the twenty keys (`array:`), requires the original seven (`present`;
 * the pattern, sector context and table column keys of
 * screener-table-column-controls are `sometimes` so older clients keep
 * working), mirrors the screener API's value rules and then runs the
 * definition through `ScreenerFilterParser` itself; invalid input is rejected
 * with a `422`, never silently repaired or clamped.
 */
class SavedScreenerController extends Controller
{
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
     * The table column criteria (screener-table-column-controls), optional on
     * create so older clients keep working, always present on read.
     */
    private const COLUMN_KEYS = [
        'search', 'sector', 'industry', 'country', 'rvol_max',
        'close_min', 'close_max', 'change_min', 'change_max',
    ];

    /**
     * List the authenticated user's Saved Screeners ordered by `name`, then id.
     */
    public function index(Request $request): JsonResponse
    {
        $screeners = $request->user()
            ->savedScreeners()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (SavedScreener $screener): array => $this->screenerPayload($screener))
            ->all();

        return response()->json(['screeners' => $screeners]);
    }

    /**
     * Save a new Screener definition for the authenticated user.
     *
     * A duplicate `(user_id, name)` is a `422` on `errors.name` (never a silent
     * upsert); two users may reuse the same name.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeNameInput($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('saved_screeners', 'name')->where(
                    fn ($query) => $query->where('user_id', $request->user()->id),
                ),
            ],
            'filters' => [
                'required',
                'array:signal,rsi_min,rsi_max,min_rvol,price_above_sma200,ma_cross,pattern,pattern_status,sort,sector_trend,sector_rs_rank_max,'.implode(',', self::COLUMN_KEYS),
            ],
            'filters.signal' => ['present', 'array'],
            'filters.signal.*' => ['required', 'string', Rule::in(self::SIGNAL_TYPES)],
            'filters.rsi_min' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'filters.rsi_max' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'filters.min_rvol' => ['present', 'nullable', 'numeric', 'min:0'],
            'filters.price_above_sma200' => ['present', 'boolean'],
            'filters.ma_cross' => ['present', 'nullable', Rule::in(['bullish', 'bearish'])],
            'filters.pattern' => ['sometimes', 'array'],
            'filters.pattern.*' => ['required', 'string', Rule::in(ChartPattern::TYPES)],
            'filters.pattern_status' => ['sometimes', Rule::in(['any', 'forming', 'confirmed'])],
            'filters.sort' => ['present', Rule::in(ScreenerFilterParser::ALL_SORTS)],
            'filters.sector_trend' => ['sometimes', 'nullable', Rule::in(ScreenerFilterParser::SECTOR_TRENDS)],
            'filters.sector_rs_rank_max' => ['sometimes', 'nullable', 'integer', 'between:1,'.ScreenerFilterParser::SECTOR_RS_RANK_MAX],
            'filters.search' => ['sometimes', 'nullable', 'string', 'max:'.ScreenerFilterParser::SEARCH_MAX_LENGTH],
            'filters.sector' => ['sometimes', 'nullable', Rule::in(ScreenerFilterParser::SECTORS)],
            'filters.industry' => ['sometimes', 'nullable', 'string', 'max:'.ScreenerFilterParser::INDUSTRY_MAX_LENGTH],
            'filters.country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'filters.rvol_max' => ['sometimes', 'nullable', 'numeric', 'between:0,1000'],
            'filters.close_min' => ['sometimes', 'nullable', 'numeric', 'between:0,1000000'],
            'filters.close_max' => ['sometimes', 'nullable', 'numeric', 'between:0,1000000'],
            'filters.change_min' => ['sometimes', 'nullable', 'numeric', 'between:-100,1000'],
            'filters.change_max' => ['sometimes', 'nullable', 'numeric', 'between:-100,1000'],
        ]);

        // The same parser as `GET /api/screener` re-checks the definition
        // (control characters, inverted ranges, bounds), so a Saved Screener
        // can never hold criteria the public API would reject.
        $this->assertScreenerAccepts($validated['filters']);

        $filters = $this->canonicalFilters($validated['filters']);

        // Sector context filters need the Market Context entitlement
        // (market-context-patterns, C6); nothing is stored without it.
        if (ScreenerFilterParser::usesMarketContext($filters) && Gate::forUser($request->user())->denies('view-market-context')) {
            return response()->json(['message' => __('market_context.filter_unavailable')], 403);
        }

        $screener = $request->user()->savedScreeners()->create([
            'name' => $validated['name'],
            'filters' => $filters,
        ]);

        return response()->json(['screener' => $this->screenerPayload($screener)], 201);
    }

    /**
     * Delete one of the authenticated user's Saved Screeners.
     *
     * The row is resolved through the scoped relation, so another user's id is a
     * `404` and their row is untouched.
     */
    public function destroy(Request $request, string $screener): JsonResponse
    {
        $saved = $request->user()
            ->savedScreeners()
            ->whereKey($screener)
            ->first();

        if ($saved === null) {
            return response()->json(['message' => __('messages.screener_not_found')], 404);
        }

        $saved->delete();

        return response()->json(null, 204);
    }

    /**
     * Trim a string `name` in place before validation.
     *
     * A non-string value is left untouched so the `string` rule produces a
     * controlled `422` instead of a TypeError.
     */
    private function normalizeNameInput(Request $request): void
    {
        $raw = $request->input('name');

        if (is_string($raw)) {
            $request->merge(['name' => trim($raw)]);
        }
    }

    /**
     * Dedupe the stored signal list and order it canonically.
     *
     * @param  list<string>  $signals
     * @return list<string>
     */
    private function canonicalSignals(array $signals): array
    {
        $selected = array_unique($signals);

        return array_values(array_filter(
            self::SIGNAL_TYPES,
            fn (string $type): bool => in_array($type, $selected, true),
        ));
    }

    /**
     * The canonical twenty-key filter object: signals deduped and ordered, and
     * the pattern keys (chart-patterns-ui), the sector context keys
     * (market-context-patterns) and the table column keys
     * (screener-table-column-controls) defaulted when absent so
     * Saved Screeners created before them keep loading.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function canonicalFilters(array $filters): array
    {
        $patterns = array_unique(is_array($filters['pattern'] ?? null) ? $filters['pattern'] : []);

        return [
            'signal' => $this->canonicalSignals(is_array($filters['signal'] ?? null) ? $filters['signal'] : []),
            'rsi_min' => $filters['rsi_min'] ?? null,
            'rsi_max' => $filters['rsi_max'] ?? null,
            'min_rvol' => $filters['min_rvol'] ?? null,
            'price_above_sma200' => (bool) ($filters['price_above_sma200'] ?? false),
            'ma_cross' => $filters['ma_cross'] ?? null,
            'pattern' => array_values(array_filter(
                ChartPattern::TYPES,
                fn (string $type): bool => in_array($type, $patterns, true),
            )),
            'pattern_status' => in_array($filters['pattern_status'] ?? null, ['any', 'forming', 'confirmed'], true)
                ? $filters['pattern_status']
                : 'any',
            'sort' => $filters['sort'] ?? 'rvol_desc',
            // market-context-patterns (C6): null on rows saved before them.
            'sector_trend' => in_array($filters['sector_trend'] ?? null, ScreenerFilterParser::SECTOR_TRENDS, true)
                ? $filters['sector_trend']
                : null,
            'sector_rs_rank_max' => is_numeric($filters['sector_rs_rank_max'] ?? null)
                ? (int) $filters['sector_rs_rank_max']
                : null,
            // screener-table-column-controls: null on rows saved before them.
            'search' => $this->canonicalText($filters['search'] ?? null),
            'sector' => in_array($filters['sector'] ?? null, ScreenerFilterParser::SECTORS, true) ? $filters['sector'] : null,
            'industry' => $this->canonicalText($filters['industry'] ?? null),
            'country' => is_string($filters['country'] ?? null) && preg_match('/^[A-Z]{2}$/', $filters['country']) === 1 ? $filters['country'] : null,
            'rvol_max' => is_numeric($filters['rvol_max'] ?? null) ? $filters['rvol_max'] + 0 : null,
            'close_min' => is_numeric($filters['close_min'] ?? null) ? $filters['close_min'] + 0 : null,
            'close_max' => is_numeric($filters['close_max'] ?? null) ? $filters['close_max'] + 0 : null,
            'change_min' => is_numeric($filters['change_min'] ?? null) ? $filters['change_min'] + 0 : null,
            'change_max' => is_numeric($filters['change_max'] ?? null) ? $filters['change_max'] + 0 : null,
        ];
    }

    /**
     * A text fragment trimmed with inner whitespace collapsed (as the parser
     * does), or `null` when blank or not a valid UTF-8 string.
     */
    private function canonicalText(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $text === '' ? null : $text;
    }

    /**
     * Run a canonical definition through `ScreenerFilterParser`; its errors
     * are returned under `filters.<key>` like the request rules' errors.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws ValidationException
     */
    private function assertScreenerAccepts(array $filters): void
    {
        try {
            ScreenerFilterParser::fromArray($filters);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['filters.'.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Shape one Saved Screener for the API.
     *
     * @return array{id: int, name: string, filters: array<string, mixed>}
     */
    private function screenerPayload(SavedScreener $screener): array
    {
        return [
            'id' => $screener->id,
            'name' => $screener->name,
            'filters' => $this->canonicalFilters(is_array($screener->filters) ? $screener->filters : []),
        ];
    }
}
