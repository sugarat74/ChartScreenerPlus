<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Services\CompanyCard\CompanyCardService;
use App\Services\CompanyCard\CompanyCardUnavailable;
use App\Services\Fundamentals\CompanyFundamentalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * AI Company Card for one Instrument (docs/specs/ai-company-card.md).
 *
 * Both routes sit inside `auth:sanctum` (a Visitor gets 401 and never reaches
 * the provider). `show` only reads the stored card for the request locale;
 * `store` reuses a fresh card or generates one. `available` on `show` tells
 * the SPA whether generation is switched on. Both successful responses carry
 * `fundamentals` (SEC-derived figures, a database read that works even with
 * the AI provider disabled; null while the SEC feature is not configured).
 * Error `code` and status never depend on the locale; only `message` is
 * localized.
 */
class CompanyCardController extends Controller
{
    public function show(string $ticker, CompanyCardService $service, CompanyFundamentalsService $fundamentals): JsonResponse
    {
        $instrument = $this->instrument($ticker);
        if ($instrument === null) {
            return $this->notFound();
        }

        return response()->json([
            'card' => $service->current($instrument, $this->locale()),
            // Lets the panel show "not available yet" instead of a button
            // that can only fail; it reveals no configuration detail.
            'available' => $service->available(),
            'fundamentals' => $fundamentals->payload($instrument),
        ]);
    }

    public function store(Request $request, string $ticker, CompanyCardService $service, CompanyFundamentalsService $fundamentals): JsonResponse
    {
        $instrument = $this->instrument($ticker);
        if ($instrument === null) {
            return $this->notFound();
        }

        try {
            return response()->json($service->generate($instrument, $this->locale(), $request->user())
                + ['fundamentals' => $fundamentals->payload($instrument)]);
        } catch (CompanyCardUnavailable $exception) {
            return response()->json([
                'code' => $exception->reason,
                'message' => __('company_card.'.$exception->reason),
            ], $exception->status());
        }
    }

    /**
     * The stock Instrument for `$ticker`; an ETF has no Company Card (404).
     */
    private function instrument(string $ticker): ?Instrument
    {
        return Instrument::query()->stocks()->where('ticker', strtoupper(trim($ticker)))->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('messages.instrument_not_found')], 404);
    }

    private function locale(): string
    {
        $locale = App::getLocale();

        return in_array($locale, (array) config('locales.supported'), true) ? $locale : (string) config('locales.default');
    }
}
