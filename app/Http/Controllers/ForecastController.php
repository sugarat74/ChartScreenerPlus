<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\ForecastUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ForecastController extends Controller
{
    public function store(Request $request, string $ticker, ForecastService $service): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'integer', Rule::in(config('forecast.horizons'))],
            'minimum_probability' => ['required', 'numeric', 'between:0,100'],
        ]);
        // Stocks only: the forecast state reads Signals, which ETFs never get.
        $instrument = Instrument::query()->stocks()->where('ticker', strtoupper($ticker))->firstOrFail();

        // The forecast model is trained on US stocks only (docs/specs/multi-market-universes.md, D10).
        if (! $instrument->supportsUsOnlyFeatures()) {
            return response()->json(['message' => __('messages.not_available_for_market')], 404);
        }

        try {
            return response()->json(['forecast' => $service->predict($instrument, (int) $validated['horizon'], (float) $validated['minimum_probability'])]);
        } catch (ForecastUnavailable $exception) {
            $reason = $exception->reason;
            $status = $reason === 'insufficient_data' ? 422 : 503;

            return response()->json([
                'code' => $reason,
                'message' => __('forecast.'.$reason),
            ], $status);
        }
    }
}
