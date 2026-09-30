<?php

use App\Services\Market\MarketCalendar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
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
| One event fires on weekdays after the configured US market close, evaluated
| in the market timezone so the wall-clock time is DST-aware (the UTC fire
| instant shifts by an hour across DST). config/app.php stays UTC; only the
| event carries the market timezone.
|
| Weekends are skipped by `weekdays()` and committed NYSE holidays by the
| `skip()` filter; `ingestion:pipeline` re-checks the trading day as defense in
| depth. A missed run is triggered manually via `ingestion:pipeline` (or
| `ingestion:run`), not by catch-up logic. Production still needs a host
| scheduler/cron entry; this only defines the schedule.
|
*/

$timezone = config('ingestion.timezone');

$runTime = Carbon::createFromFormat('H:i', config('ingestion.market_close'), $timezone)
    ->addMinutes((int) config('ingestion.schedule_buffer_minutes'))
    ->format('H:i');

Schedule::command('ingestion:pipeline', ['--universe' => config('ingestion.universe')])
    ->name('ingestion-pipeline')
    ->dailyAt($runTime)
    ->timezone($timezone)
    ->weekdays()
    ->skip(fn () => app(MarketCalendar::class)->isHoliday(now($timezone)))
    ->withoutOverlapping(120);
