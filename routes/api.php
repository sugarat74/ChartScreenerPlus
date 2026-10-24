<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\IngestionRunController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertEmailPreferenceController;
use App\Http\Controllers\AlertEmailUnsubscribeController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CompanyCardController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\Admin\ForecastReportController;
use App\Http\Controllers\Admin\ForecastBacktestController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\InstrumentMarketContextController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MarketContextController;
use App\Http\Controllers\Admin\MarketContextAdminController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SavedScreenerController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\ScreenerInterpretController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

// First-party SPA auth (Sanctum session cookie + CSRF). No API tokens.
// Every `throttle:max,decay,prefix` here carries its own prefix: without it the
// counter is keyed only by IP (or user), so public browsing spent the login
// budget and a visitor got 429 on their first sign-in.
// Registration sends an email once verification is on, so it is throttled
// per IP (mass sign-ups would mail many addresses and burn the provider's
// daily cap).
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,60,register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1,login');
Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:6,1,password-forgot');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:12,1,password-reset');

// Public read-only market data: browsing charts requires no session
// (docs/user-and-access-model.md). The payload is bounded and the 404 for an
// unknown ticker is returned as JSON by the controller.
Route::get('/instruments/{ticker}', [InstrumentController::class, 'show']);

// Public read-only screener: anonymous like the instrument endpoint and
// throttled (`60,1`) so anonymous browsing stays bounded. `limit` is clamped;
// bad semantic filter/sort values are a `422` and an unknown universe is a JSON
// `404` (`CONSTRAINTS.md` -> Public API).
Route::get('/screener', [ScreenerController::class, 'index'])->middleware('throttle:60,1,screener');

// Public facts for the legal pages (owner identity once configured, retention
// figures). Anonymous and throttled; see docs/specs/legal-compliance-eu.md.
Route::get('/legal', [LegalController::class, 'show'])->middleware('throttle:60,1,legal');

// Daily alert email unsubscribe (alerts-email-digest): no session, a valid
// signature over the user id instead (403 otherwise). GET only shows a page
// with a confirm button (link scanners must not unsubscribe anyone); POST
// unsubscribes: the button's form or the RFC 8058 one-click request.
Route::get('/email/alerts/unsubscribe/{user}', [AlertEmailUnsubscribeController::class, 'show'])
    ->whereNumber('user')->middleware(['signed:relative', 'throttle:30,1,alert-email-unsubscribe'])->name('alert-email.unsubscribe');
Route::post('/email/alerts/unsubscribe/{user}', [AlertEmailUnsubscribeController::class, 'unsubscribe'])
    ->whereNumber('user')->middleware(['signed:relative', 'throttle:30,1,alert-email-unsubscribe'])->name('alert-email.unsubscribe.one-click');

// Email verification (auth-email-verification). The SPA page
// `/verificar-correo/{user}/{hash}?expires=…&signature=…` POSTs the emailed
// link here: the relative signature is the credential (no session needed,
// nobody is signed in) and a GET never verifies anything (mail link scanners).
// The SPA sends the CSRF token, so there is no CSRF exemption.
Route::post('/email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])
    ->whereNumber('user')->where('hash', '[a-f0-9]{40}')
    ->middleware('throttle:12,1,email-verify')->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    // Reachable while an account still has to confirm its email
    // (auth-email-verification): the session itself, sign-out, the account
    // (GDPR export and deletion never depend on verification; deleting is the
    // way out of a mistyped address) and the resend. EVERY other account-only
    // route goes in the `verified` group below, which answers a pending
    // account with a JSON 403 `email_verification.required` (never a
    // redirect). EmailVerificationTest checks this structurally.
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Account self-service (account-self-service): always the session user,
    // session-only (400 without a stateful session) and never a `user_id`.
    // Admins cannot delete themselves (422 account.admin_cannot_delete).
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:30,1,account-profile');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:6,1,account-password');
    Route::get('/account/export', [AccountController::class, 'export'])->middleware('throttle:5,1,account-export');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:6,1,account-delete');

    // A fresh confirmation email for the session user (202 sent, 200 already
    // verified, 409 while verification is not required). Keyed by user.
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,60,email-verification-resend');

    Route::middleware('verified')->group(function () {
        Route::post('/instruments/{ticker}/forecast', [ForecastController::class, 'store'])->middleware('throttle:'.config('forecast.requests_per_minute').',1,forecast');

        // AI Company Card (ai-company-card): GET only reads the stored card for the
        // request locale and never calls the provider; POST reuses a fresh card or
        // generates one under per-user and global daily caps.
        Route::get('/instruments/{ticker}/company-card', [CompanyCardController::class, 'show'])->middleware('throttle:60,1,ai-card-read');
        Route::post('/instruments/{ticker}/company-card', [CompanyCardController::class, 'store'])->middleware('throttle:'.config('ai_card.requests_per_minute').',1,ai-card');
        // Natural-language Screener (ai-screener-natural-language): turns a
        // free-text request into the public `GET /api/screener` filters under
        // per-user and global daily caps. The query text is never stored or logged.
        Route::post('/screener/interpret', ScreenerInterpretController::class)->middleware('throttle:'.config('ai_screener.requests_per_minute').',1,ai-screener');

        // Personal watchlist: always scoped to the authenticated user, never to a
        // client-supplied `user_id`. `auth:sanctum` rejects a guest with 401.
        Route::get('/watchlist', [WatchlistController::class, 'index']);
        Route::post('/watchlist', [WatchlistController::class, 'store']);
        Route::delete('/watchlist/{ticker}', [WatchlistController::class, 'destroy']);

        // Alerts and their in-app notifications (alerts-engine): scoped to the
        // authenticated user like Saved Screeners; foreign ids are a 404.
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alerts', [AlertController::class, 'store']);
        Route::patch('/alerts/{alert}', [AlertController::class, 'update']);
        Route::delete('/alerts/{alert}', [AlertController::class, 'destroy']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        // Daily alert email opt-in (alerts-email-digest): default off, explicit.
        Route::get('/alerts/email', [AlertEmailPreferenceController::class, 'show']);
        Route::put('/alerts/email', [AlertEmailPreferenceController::class, 'update'])->middleware('throttle:20,1,alert-email-preference');
        Route::post('/notifications/read', [NotificationController::class, 'markRead']);

        // Saved Screeners: user-owned named screener definitions. Every query is
        // scoped to `$request->user()->savedScreeners()`; no endpoint accepts a
        // `user_id`. `/screeners` does not collide with the public `/screener`.
        Route::get('/screeners', [SavedScreenerController::class, 'index']);
        Route::post('/screeners', [SavedScreenerController::class, 'store']);
        Route::delete('/screeners/{screener}', [SavedScreenerController::class, 'destroy']);

        // Market Context (market-context-ui, market-context-patterns): Registered
        // Users only, through the single `view-market-context` ability (guest 401
        // from `auth:sanctum`; a user without access gets a JSON 404 while
        // MARKET_CONTEXT_PUBLIC is off; Admins always, as a preview). The ability
        // is the paid-plan seam: no route checks the role or the flag itself.
        Route::middleware('can:view-market-context')->group(function () {
            Route::get('/market-context', [MarketContextController::class, 'show'])
                ->middleware('throttle:60,1,market-context');
            // A closed metric list (422 otherwise).
            Route::get('/market-context/history', [MarketContextController::class, 'history'])
                ->middleware('throttle:60,1,market-context-history');
            // A stock's sector ETF, sector trend and rank and the Market Regime
            // (404 for an ETF or an unknown ticker).
            Route::get('/instruments/{ticker}/market-context', [InstrumentMarketContextController::class, 'show'])
                ->middleware('throttle:60,1,instrument-market-context');
        });
    });
});

// Admin-only boundary. `auth:sanctum` runs first (guests -> 401) and `admin`
// forbids authenticated non-admins (403). Later admin features extend this
// group; `/api/admin/ping` is only a guard probe, not product behavior.
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/forecasts/report', [ForecastReportController::class, 'index']);
    Route::get('/forecasts/backtests', [ForecastBacktestController::class, 'index']);
    Route::post('/forecasts/backtests', [ForecastBacktestController::class, 'store']);
    Route::get('/forecasts/backtests/{run}', [ForecastBacktestController::class, 'show'])->whereNumber('run');
    Route::get('/ping', fn () => response()->json(['ok' => true]));

    // Admin ingestion panel: trigger a run, follow it and retry only its
    // failures. Execution is a queued RunIngestionJob (sync by default).
    Route::get('/ingestion/runs', [IngestionRunController::class, 'index']);
    Route::post('/ingestion/runs', [IngestionRunController::class, 'store']);
    Route::get('/ingestion/runs/{run}', [IngestionRunController::class, 'show']);
    Route::post('/ingestion/runs/{run}/retry', [IngestionRunController::class, 'retry']);

    // Admin overview of users, sessions and sign-in activity
    // (admin-users-sessions). Read-only except ending sessions; session ids
    // are never exposed (opaque `ref`), and the caller's own current session
    // is protected from revocation.
    Route::get('/users/summary', [UserController::class, 'summary']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
    Route::delete('/users/{user}/sessions', [UserController::class, 'revokeSessions'])->whereNumber('user');
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::delete('/sessions/{reference}', [SessionController::class, 'destroy'])->where('reference', '[a-f0-9]{40}');
    Route::get('/activity', [ActivityController::class, 'index']);

    // Market Context status and reruns (market-context-ui): only the three
    // commands, without options, as a queued RunMarketContextCommand.
    Route::get('/market-context', [MarketContextAdminController::class, 'show']);
    Route::post('/market-context/runs', [MarketContextAdminController::class, 'rerun']);
});
