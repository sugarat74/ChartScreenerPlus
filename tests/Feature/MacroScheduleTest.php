<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The daily `macro-refresh` event (docs/specs/macro-series-fred.md, Scenario 1).
 */
class MacroScheduleTest extends TestCase
{
    private function macroEvent(): Event
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => $event->description === 'macro-refresh')->values();
        $this->assertCount(1, $events);

        return $events->first();
    }

    public function test_the_event_runs_daily_at_ten_new_york_only_when_enabled(): void
    {
        $event = $this->macroEvent();

        // Every day, weekends included.
        $this->assertSame('0 10 * * *', $event->expression);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('macro:refresh', (string) $event->command);

        // Saturday 2026-10-24 10:00 EDT = 14:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-24 14:00:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
        $this->assertFalse($event->filtersPass($this->app), 'disabled by default: the event does not run');
        config()->set('macro.enabled', true);
        $this->assertTrue($event->filtersPass($this->app));
        Carbon::setTestNow();
    }
}
