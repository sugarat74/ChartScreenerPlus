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
        $instrument = Instrument::query()->where('ticker', strtoupper($ticker))->firstOrFail();

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
