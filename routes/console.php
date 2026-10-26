<?php

use App\Models\AiCompanyCardGeneration;
use App\Models\AiScreenerQuery;
use App\Models\AlertEmailDigest;
use App\Models\LoginEvent;
use App\Services\Market\PipelineSchedule;
use Illuminate\Console\Scheduling\Schedule as ScheduleManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Daily EOD pipeline
|--------------------------------------------------------------------------
|
| One event per Listing Market (App\Services\Market\PipelineSchedule) fires on
| weekdays after that market's close plus buffer (US 16:30 New York; Madrid and
| Xetra 18:15 local when a Screener universe of that market is seeded),
| evaluated in the market timezone so the wall-clock time is DST-aware (the UTC
| fire instant shifts by an hour across DST). config/app.php stays UTC; only
| the event carries the market timezone.
|
| Weekends are skipped by `weekdays()` and the market's committed holidays by
| the `skip()` filter; `ingestion:pipeline` re-checks the trading day as defense
| in depth. A missed run is triggered manually via `ingestion:pipeline` (or
| `ingestion:run`), not by catch-up logic. Production still needs a host
| scheduler/cron entry; this only defines the schedule.
|
*/

$timezone = config('ingestion.timezone');

app(PipelineSchedule::class)->register(app(ScheduleManager::class));

/*
|--------------------------------------------------------------------------
| Company Fundamentals (SEC EDGAR)
|--------------------------------------------------------------------------
|
| Separate from the EOD pipeline (own command, own lock, never part of
| `ingestion:pipeline`): weekdays at 07:00 in the market timezone, only when
| SEC_FUNDAMENTALS_ENABLED is true (the command also refuses to run without a
| valid SEC_USER_AGENT). One submissions request per company; companyfacts
| only when a new 10-K/10-Q is listed or a filing is still pending.
|
*/

Schedule::command('fundamentals:refresh')
    ->name('fundamentals-refresh')
    ->dailyAt('07:00')
    ->timezone($timezone)
    ->weekdays()
    ->when(fn (): bool => (bool) config('fundamentals.enabled'))
    ->withoutOverlapping(90)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Macro Series (FRED)
|--------------------------------------------------------------------------
|
| Daily at config('macro.schedule_time') in the market timezone, weekends
| included, so weekly releases are picked up without a market session. Only
| when MACRO_ENABLED is true (the command also needs a valid FRED_API_KEY).
| The EOD pipeline runs the same command as an isolated stage; both share the
| command's own lock. See docs/specs/macro-series-fred.md.
|
*/

Schedule::command('macro:refresh')
    ->name('macro-refresh')
    ->dailyAt(config('macro.schedule_time'))
    ->timezone($timezone)
    ->when(fn (): bool => (bool) config('macro.enabled'))
    ->withoutOverlapping(30)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Personal-data retention
|--------------------------------------------------------------------------
|
| Sign-in activity (email, IP, user agent) older than
| config('admin.activity_retention_days') (90) is deleted daily, and so are
| in-app alert notifications older than
| config('alerts.notification_retention_days') (90). The AI Company Card
| generation log (account id, Instrument, status, token counts; no prompt or
| response text) is pruned after config('ai_card.log_retention_days') (90),
| and the natural-language Screener log (account id, length, outcome, token
| counts; never the query text) after config('ai_screener.log_retention_days')
| (90). The daily alert email ledger (account id, as-of date, item count,
| sent time) after config('alerts.email_digest.ledger_retention_days') (90).
| Daily server log files (`laravel-YYYY-MM-DD.log`, which may hold personal
| data) older than config('legal.server_log_retention_days') (14) are deleted
| by logs:prune, because Monolog keeps a number of files, not of days.
|
*/

Schedule::command('model:prune', ['--model' => [LoginEvent::class, AiCompanyCardGeneration::class, AiScreenerQuery::class, AlertEmailDigest::class]])
    ->name('prune-login-events')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('notifications:prune')
    ->name('prune-notifications')
    ->dailyAt('03:20')
    ->withoutOverlapping();

Schedule::command('logs:prune')
    ->name('prune-server-logs')
    ->dailyAt('03:25')
    ->withoutOverlapping();
