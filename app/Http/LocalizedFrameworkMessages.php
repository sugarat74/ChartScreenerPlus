<?php

namespace App\Http;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translate the framework's generic JSON error messages ("Unauthenticated.",
 * "Too Many Attempts.", "CSRF token mismatch.", route-miss 404, "Server
 * Error"...) for API responses.
 *
 * Only a message that is one of the framework defaults for its status is
 * replaced: application messages (already localized through `__()`) pass
 * through untouched. Status code, headers (e.g. `Retry-After`) and every other
 * JSON key are preserved. The locale is resolved from the request because a
 * route miss never runs the `api` middleware group.
 */
class LocalizedFrameworkMessages
{
    /**
     * Status => [translation key, framework default messages (regex)].
     *
     * @var array<int, array{0: string, 1: list<string>}>
     */
    private const DEFAULTS = [
        401 => ['messages.http.unauthenticated', ['/^Unauthenticated\.$/']],
        403 => ['messages.http.forbidden', ['/^This action is unauthorized\.$/', '/^Forbidden$/']],
        404 => ['messages.http.not_found', ['/^The route .+ could not be found\.$/', '/^No query results for model/', '/^Not Found$/']],
        405 => ['messages.http.method_not_allowed', ['/^The [A-Z]+ method is not supported for route /', '/^Method Not Allowed$/']],
        419 => ['messages.http.session_expired', ['/^CSRF token mismatch\.$/', '/^Page Expired$/']],
        429 => ['messages.http.too_many_attempts', ['/^Too Many Attempts\.$/', '/^Too Many Requests$/']],
        500 => ['messages.http.server_error', ['/^Server Error$/', '/^Internal Server Error$/']],
        503 => ['messages.http.service_unavailable', ['/^Service Unavailable$/']],
    ];

    public static function localize(Response $response, Request $request): Response
    {
        if (! $response instanceof JsonResponse || ! $request->is('api/*')) {
            return $response;
        }

        $definition = self::DEFAULTS[$response->getStatusCode()] ?? null;
        $data = $response->getData(true);

        if ($definition === null || ! is_array($data) || ! array_key_exists('message', $data)) {
            return $response;
        }

        [$key, $patterns] = $definition;
        $message = is_string($data['message']) ? trim($data['message']) : '';
        $isDefault = $message === '';
        foreach ($patterns as $pattern) {
            $isDefault = $isDefault || preg_match($pattern, $message) === 1;
        }

        $locale = SetLocale::resolve($request);
        $response->headers->set('Content-Language', $locale);

        if (! $isDefault) {
            return $response;
        }

        $data['message'] = __($key, [], $locale);

        return $response->setData($data);
    }
}
