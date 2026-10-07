<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\IngestionRunController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\SavedScreenerController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

// First-party SPA auth (Sanctum session cookie + CSRF). No API tokens.
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

// Public read-only market data: browsing charts requires no session
// (docs/user-and-access-model.md). The payload is bounded and the 404 for an
// unknown ticker is returned as JSON by the controller.
Route::get('/instruments/{ticker}', [InstrumentController::class, 'show']);

// Public read-only screener: anonymous like the instrument endpoint and
// throttled (`60,1`) so anonymous browsing stays bounded. `limit` is clamped;
// bad semantic filter/sort values are a `422` and an unknown universe is a JSON
// `404` (`CONSTRAINTS.md` -> Public API).
Route::get('/screener', [ScreenerController::class, 'index'])->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Personal watchlist: always scoped to the authenticated user, never to a
    // client-supplied `user_id`. `auth:sanctum` rejects a guest with 401.
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::post('/watchlist', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/{ticker}', [WatchlistController::class, 'destroy']);

    // Saved Screeners: user-owned named screener definitions. Every query is
    // scoped to `$request->user()->savedScreeners()`; no endpoint accepts a
    // `user_id`. `/screeners` does not collide with the public `/screener`.
    Route::get('/screeners', [SavedScreenerController::class, 'index']);
    Route::post('/screeners', [SavedScreenerController::class, 'store']);
    Route::delete('/screeners/{screener}', [SavedScreenerController::class, 'destroy']);
});

// Admin-only boundary. `auth:sanctum` runs first (guests -> 401) and `admin`
// forbids authenticated non-admins (403). Later admin features extend this
// group; `/api/admin/ping` is only a guard probe, not product behavior.
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
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
});
