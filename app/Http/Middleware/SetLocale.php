<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Localize API messages from the request's `Accept-Language`.
 *
 * The SPA sends its selected language on every call. The first supported
 * language in preference order wins (regional variants match their primary
 * subtag, e.g. `en-GB` -> `en`); an absent header or an unsupported/invalid
 * one falls back to `config('locales.default')`. Only message text changes:
 * status codes, response shapes, `errors` keys and Signal codes do not.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request);
        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    public static function resolve(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('locales.supported');
        $default = (string) config('locales.default');

        $header = $request->headers->get('Accept-Language');
        if ($header === null || trim($header) === '') {
            return $default;
        }

        // getLanguages() is ordered by q-value and normalizes `en-US` to `en_US`.
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }
}
