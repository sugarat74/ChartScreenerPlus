<?php

use App\Http\LocalizedFrameworkMessages;
use App\Http\Middleware\EnsureMarketContextPublic;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA auth: apply Sanctum's stateful middleware
        // (session cookie + CSRF) to requests from SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();
        // The daily alert email unsubscribe (alerts-email-digest) is protected by
        // its signature, not a session: its confirmation form and RFC 8058
        // one-click POSTs from mail clients must not need a CSRF token.
        $middleware->validateCsrfTokens(except: ['api/email/alerts/unsubscribe/*']);

        // Localize API messages from Accept-Language before any other API
        // middleware (auth/admin aborts included) can produce a message.
        $middleware->api(prepend: [SetLocale::class]);

        // Server-side admin boundary. Applied to admin route groups after
        // `auth:sanctum` so guests get 401 and non-admins 403.
        // The Market Context go-live switch (market-context-ui): public once
        // MARKET_CONTEXT_PUBLIC is on, Admin preview before. A release gate,
        // not authorization.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'market-context.public' => EnsureMarketContextPublic::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Translate the framework's generic API error messages (401, 419,
        // 429, route-miss 404, 500...) without touching status or headers.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request) => LocalizedFrameworkMessages::localize($response, $request),
        );
    })->create();
