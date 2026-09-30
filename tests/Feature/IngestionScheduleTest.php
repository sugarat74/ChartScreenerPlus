<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IngestionScheduleTest extends TestCase
{
    /**
     * Bootstrap the console kernel (which requires routes/console.php) and
     * return the single `ingestion-pipeline` scheduled event.
     */
    private function pipelineEvent(): Event
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => $event->description === 'ingestion-pipeline')
            ->values();

        $this->assertCount(1, $events);

        return $events->first();
    }

    public function test_exactly_one_pipeline_event_runs_after_close_on_weekdays(): void
    {
        $event = $this->pipelineEvent();

        // market_close 16:00 + buffer 30, weekdays only.
        $this->assertSame('30 16 * * 1-5', $event->expression);
        // Wall-clock time is evaluated in the US market timezone, not UTC.
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
        $this->assertStringContainsString('ingestion:pipeline', (string) $event->command);
        $this->assertStringContainsString('sp500', (string) $event->command);
    }

    public function test_the_schedule_is_dst_aware(): void
    {
        $event = $this->pipelineEvent();

        // 16:30 EDT in summer (UTC-4): 2026-07-15 is a Wednesday.
        Carbon::setTestNow(Carbon::parse('2026-07-15 20:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));

        // 16:30 EST in winter (UTC-5), a different UTC instant for the same
        // wall-clock market time: 2026-01-14 is a Wednesday.
        Carbon::setTestNow(Carbon::parse('2026-01-14 21:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));

        // 15:30 EST is before close + buffer, so not due; this also proves the
        // event is not firing on the UTC wall clock.
        Carbon::setTestNow(Carbon::parse('2026-01-14 20:30:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }

    public function test_the_schedule_skips_weekends_and_committed_holidays(): void
    {
        $event = $this->pipelineEvent();

        // A normal weekday: no filter rejects it (the overlap mutex is free).
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
        $this->assertTrue($event->filtersPass($this->app));

        // A committed NYSE holiday: the schedule's skip filter rejects it.
        Carbon::setTestNow(Carbon::parse('2026-07-03 16:30:00', 'America/New_York'));
        $this->assertFalse($event->filtersPass($this->app));
    }
}
