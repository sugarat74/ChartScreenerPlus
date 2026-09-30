<?php

use App\Http\Controllers\Admin\IngestionRunController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\ScreenerController;
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
});
