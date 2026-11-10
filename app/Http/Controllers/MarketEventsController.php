<?php

namespace App\Http\Controllers;

use App\Services\MarketContext\MarketContextPayload;
use App\Services\MarketContext\MarketEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MarketEventsController extends Controller
{
    public function __invoke(Request $request, MarketEvents $events): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'required', 'integer', 'in:14,30,90'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'kind' => ['sometimes', 'required', 'in:central_bank,macro_release'],
            'region' => ['sometimes', 'required', 'in:US,EU'],
        ]);
        $today = Carbon::parse(MarketContextPayload::today());
        $from = $validated['from'] ?? $today->copy()->subDays(7)->toDateString();
        $to = $validated['to'] ?? $today->copy()->addDays((int) ($validated['days'] ?? 14))->toDateString();
        if ($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 100) {
            throw ValidationException::withMessages(['to' => [__('market_context.events_range_invalid')]]);
        }

        return response()->json($events->build($from, $to, $validated['kind'] ?? null, $validated['region'] ?? null));
    }
}
