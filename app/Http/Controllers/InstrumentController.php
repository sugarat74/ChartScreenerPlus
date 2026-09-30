<?php

namespace App\Http\Controllers;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only instrument detail for the chart screen.
 *
 * The route is anonymous by design: browsing charts requires no session
 * (`docs/user-and-access-model.md`), and the data is system-owned public market
 * data, so there is no ownership or authorization branch here. The payload is
 * bounded (most recent bars only), ordered and explicit; decimal columns are
 * converted to JSON numbers at this boundary so chart consumers get numbers
 * while storage stays `decimal` (`CONSTRAINTS.md`).
 */
class InstrumentController extends Controller
{
    /**
     * Default number of most recent bars returned when `?limit` is absent.
     */
    private const DEFAULT_LIMIT = 252;

    /**
     * Hard cap on the number of bars returned, whatever `?limit` says.
     */
    private const MAX_LIMIT = 2000;

    /**
     * Return one instrument's metadata, bounded bars, latest snapshot and
     * current signals.
     */
    public function show(Request $request, string $ticker): JsonResponse
    {
        $ticker = strtoupper(trim($ticker));

        $instrument = Instrument::query()->where('ticker', $ticker)->first();

        if ($instrument === null) {
            return response()->json(['message' => 'Instrument not found.'], 404);
        }

        $limit = $this->resolveLimit($request);

        // The database selects the newest `$limit` bars; the payload presents
        // them ascending so a candle chart can render left-to-right directly.
        $bars = $instrument->dailyBars()
            ->orderByDesc('date')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (DailyBar $bar): array => $this->barPayload($bar))
            ->all();

        $snapshot = $instrument->indicatorSnapshots()
            ->orderByDesc('date')
            ->first();

        $signals = $instrument->signals()
            ->orderBy('type')
            ->get()
            ->map(fn (Signal $signal): array => $this->signalPayload($signal))
            ->all();

        return response()->json([
            'instrument' => $this->instrumentPayload($instrument),
            'bars' => $bars,
            'snapshot' => $snapshot === null ? null : $this->snapshotPayload($snapshot),
            'signals' => $signals,
            'meta' => [
                'limit' => $limit,
                'bar_count' => count($bars),
                'latest_bar_date' => $bars === [] ? null : $bars[array_key_last($bars)]['date'],
            ],
        ]);
    }

    /**
     * Resolve `?limit` to a bounded integer.
     *
     * A non-numeric value falls back to the default and the value is clamped
     * silently to `1..2000` (mirroring the admin `limit` precedent); it is
     * never a validation error.
     */
    private function resolveLimit(Request $request): int
    {
        if (! is_numeric($request->query('limit'))) {
            return self::DEFAULT_LIMIT;
        }

        return min(max((int) $request->query('limit'), 1), self::MAX_LIMIT);
    }

    /**
     * Shape one instrument for the API.
     *
     * @return array<string, mixed>
     */
    private function instrumentPayload(Instrument $instrument): array
    {
        return [
            'ticker' => $instrument->ticker,
            'company' => $instrument->company,
            'sector' => $instrument->sector,
            'exchange' => $instrument->exchange,
            'active' => $instrument->active,
        ];
    }

    /**
     * Shape one daily bar for the API.
     *
     * @return array<string, mixed>
     */
    private function barPayload(DailyBar $bar): array
    {
        return [
            'date' => $bar->date->format('Y-m-d'),
            'open' => (float) $bar->open,
            'high' => (float) $bar->high,
            'low' => (float) $bar->low,
            'close' => (float) $bar->close,
            'volume' => (int) $bar->volume,
        ];
    }

    /**
     * Shape the latest indicator snapshot for the API.
     *
     * Every indicator key is always present; a `null` means history was
     * insufficient at that date (matching `indicators-compute`).
     *
     * @return array<string, mixed>
     */
    private function snapshotPayload(IndicatorSnapshot $snapshot): array
    {
        return [
            'date' => $snapshot->date->format('Y-m-d'),
            'sma20' => $this->nullableFloat($snapshot->sma20),
            'sma50' => $this->nullableFloat($snapshot->sma50),
            'sma200' => $this->nullableFloat($snapshot->sma200),
            'ema21' => $this->nullableFloat($snapshot->ema21),
            'ema55' => $this->nullableFloat($snapshot->ema55),
            'rsi14' => $this->nullableFloat($snapshot->rsi14),
            'adx' => $this->nullableFloat($snapshot->adx),
            'macd' => $this->nullableFloat($snapshot->macd),
            'macd_signal' => $this->nullableFloat($snapshot->macd_signal),
            'macd_hist' => $this->nullableFloat($snapshot->macd_hist),
            'bb_upper' => $this->nullableFloat($snapshot->bb_upper),
            'bb_middle' => $this->nullableFloat($snapshot->bb_middle),
            'bb_lower' => $this->nullableFloat($snapshot->bb_lower),
            'rvol' => $this->nullableFloat($snapshot->rvol),
        ];
    }

    /**
     * Shape one active signal for the API.
     *
     * @return array<string, mixed>
     */
    private function signalPayload(Signal $signal): array
    {
        return [
            'type' => $signal->type,
            'date' => $signal->date->format('Y-m-d'),
            'metadata' => $signal->metadata,
        ];
    }

    /**
     * Convert a nullable `decimal:4` cast value to a JSON number or `null`.
     */
    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
