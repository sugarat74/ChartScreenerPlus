<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's personal watchlist.
 *
 * Ownership is enforced server-side: every query runs through
 * `$request->user()->watchlist()`, and no endpoint accepts a `user_id` from the
 * client. Another user's ticker simply does not match the scoped relation, so a
 * cross-user read is an empty list and a cross-user delete is a `404` (no
 * existence leak). All three routes live inside the `auth:sanctum` group, so a
 * guest gets `401`.
 */
class WatchlistController extends Controller
{
    /**
     * List the authenticated user's watchlist entries, ordered by ticker.
     */
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->watchlist()
            ->orderBy('instruments.ticker')
            ->get()
            ->map(fn (Instrument $instrument): array => $this->entryPayload($instrument))
            ->all();

        return response()->json(['items' => $items]);
    }

    /**
     * Follow an instrument. Idempotent: re-adding an existing entry is a `200`
     * (never a duplicate row) because `syncWithoutDetaching` only attaches the
     * pair that is missing.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeTickerInput($request);

        $request->validate([
            'ticker' => ['required', 'string', 'max:20'],
        ]);

        $instrument = Instrument::query()
            ->where('ticker', $request->input('ticker'))
            ->first();

        if ($instrument === null) {
            throw ValidationException::withMessages([
                'ticker' => 'Instrumento desconocido.',
            ]);
        }

        $attached = $request->user()->watchlist()->syncWithoutDetaching([$instrument->id]);

        return response()->json(
            ['item' => $this->entryPayload($instrument)],
            $attached['attached'] !== [] ? 201 : 200,
        );
    }

    /**
     * Unfollow an instrument.
     *
     * `DELETE` is not silently idempotent here: `detach` reports `0` when the
     * row was absent or belonged to another user, which is a `404`.
     */
    public function destroy(Request $request, string $ticker): JsonResponse
    {
        $instrument = Instrument::query()
            ->where('ticker', strtoupper(trim($ticker)))
            ->first();

        if ($instrument === null) {
            return response()->json(['message' => 'Instrument not found.'], 404);
        }

        $removed = $request->user()->watchlist()->detach($instrument->id);

        if ($removed === 0) {
            return response()->json(
                ['message' => 'Instrument is not in your watchlist.'],
                404,
            );
        }

        return response()->json(null, 204);
    }

    /**
     * Normalize the raw `ticker` input in place (`trim` + `strtoupper`).
     *
     * A non-string value is left untouched so the `string` rule produces a
     * controlled `422` instead of a TypeError.
     */
    private function normalizeTickerInput(Request $request): void
    {
        $raw = $request->input('ticker');

        if (is_string($raw)) {
            $request->merge(['ticker' => strtoupper(trim($raw))]);
        }
    }

    /**
     * Shape one watchlist entry for the API (same field set as the
     * instrument-detail payload).
     *
     * @return array<string, mixed>
     */
    private function entryPayload(Instrument $instrument): array
    {
        return [
            'ticker' => $instrument->ticker,
            'company' => $instrument->company,
            'sector' => $instrument->sector,
            'exchange' => $instrument->exchange,
            'active' => $instrument->active,
        ];
    }
}
