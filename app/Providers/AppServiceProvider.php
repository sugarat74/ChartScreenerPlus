<?php

namespace App\Providers;

use App\Services\Admin\LoginEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
    }
}
