<?php

namespace App\Http\Controllers;

use App\Services\Screener\Ai\ScreenerQueryInterpreter;
use App\Services\Screener\Ai\ScreenerQueryUnavailable;
use App\Services\Screener\ScreenerFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Natural-language Screener: `POST /api/screener/interpret`
 * (docs/specs/ai-screener-natural-language.md).
 *
 * Inside `auth:sanctum` (a Visitor gets 401 and never reaches the provider)
 * and throttled per user. The answer uses the public `GET /api/screener`
 * param names, so the SPA applies it as ordinary URL filter state. Error
 * `code` and status never depend on the locale; only `message` is localized.
 * The query text is never stored or logged.
 */
class ScreenerInterpretController extends Controller
{
    public function __invoke(Request $request, ScreenerQueryInterpreter $interpreter): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:300'],
            'locale' => ['sometimes', 'string', Rule::in((array) config('locales.supported'))],
        ]);

        $locale = $validated['locale'] ?? $this->appLocale();

        try {
            $result = $interpreter->interpret((string) $validated['query'], $locale, $request->user());
        } catch (ScreenerQueryUnavailable $exception) {
            return response()->json([
                'code' => $exception->reason,
                'message' => __('ai_screener.'.$exception->reason),
            ], $exception->status());
        }

        // The cached answer is shared by every caller; the sector context
        // filters are removed per caller without the Market Context
        // entitlement and named once in `unsupported` (market-context-patterns, C7).
        if (ScreenerFilterParser::usesMarketContext($result['filters']) && Gate::forUser($request->user())->denies('view-market-context')) {
            foreach (ScreenerFilterParser::MARKET_CONTEXT_KEYS as $key) {
                $result['filters'][$key] = null;
            }

            $note = __('ai_screener.market_context_unavailable');
            if (! in_array($note, $result['unsupported'], true)) {
                $result['unsupported'][] = $note;
            }
        }

        return response()->json($result);
    }

    private function appLocale(): string
    {
        $locale = App::getLocale();

        return in_array($locale, (array) config('locales.supported'), true) ? $locale : (string) config('locales.default');
    }
}
