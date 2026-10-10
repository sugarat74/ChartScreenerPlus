<?php

namespace App\Http\Controllers;

use App\Models\ChartPattern;
use App\Models\SavedScreener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
 * The stored `filters` JSON is the canonical seven-key definition in API param
 * names (the same vocabulary as `GET /api/screener`), including `sort`, so
 * applying a Screener restores the exact filters and ranking. Create validation
 * whitelists the seven keys (`array:`), requires every one (`present`) and
 * mirrors the frozen screener API's value rules; invalid input is rejected with
 * a `422`, never silently repaired or clamped.
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
     * The supported sort orders (mirrors the frozen screener API).
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
                'array:signal,rsi_min,rsi_max,min_rvol,price_above_sma200,ma_cross,pattern,pattern_status,sort',
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
            'filters.sort' => ['present', Rule::in(self::SORTS)],
        ]);

        $filters = $this->canonicalFilters($validated['filters']);

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
     * The canonical nine-key filter object: signals deduped and ordered, and
     * the pattern keys (added by chart-patterns-ui) defaulted when absent so
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
        ];
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
