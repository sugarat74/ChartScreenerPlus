<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Admin\LoginEventRecorder;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-in activity for the Admin overview (admin-users-sessions).
        Event::listen(Login::class, [LoginEventRecorder::class, 'login']);
        Event::listen(Failed::class, [LoginEventRecorder::class, 'failed']);
        Event::listen(Logout::class, [LoginEventRecorder::class, 'logout']);

        // The single Market Context entitlement (docs/specs/market-context-patterns.md,
        // C1): Registered Users once the owner's go-live flag is on, Admins
        // always (a preview). Guests never pass: the user parameter is not
        // nullable, so Laravel denies them before the closure runs.
        //
        // Paid-plan seam: a future plan changes only this closure (for example
        // `|| $user->hasPlan('pro')`, denying others with
        // `Response::denyWithStatus(403, ...)`). Every Market Context check
        // (routes, Screener filters, Saved Screeners, NL Screener, alerts and
        // the user capability) calls this ability, never the role or the flag.
        Gate::define('view-market-context', fn (User $user): Response => ($user->isAdmin() || (bool) config('market_context.public'))
            ? Response::allow()
            : Response::denyAsNotFound(__('market_context.not_available')));
    }
}
