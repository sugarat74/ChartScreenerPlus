<?php

namespace Tests\Unit;

use App\Services\Market\MarketCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarketCalendarTest extends TestCase
{
    public function test_a_configured_holiday_is_not_a_trading_day(): void
    {
        config(['ingestion.holidays' => ['2026-07-03']]);

        $calendar = new MarketCalendar;
        $date = Carbon::parse('2026-07-03 16:30:00', 'America/New_York');

        $this->assertTrue($calendar->isHoliday('US', $date));
        $this->assertFalse($calendar->isTradingDay('US', $date));
    }

    public function test_a_weekend_is_not_a_trading_day(): void
    {
        config(['ingestion.holidays' => []]);

        $calendar = new MarketCalendar;

        // 2026-07-04 is a Saturday, 2026-07-05 is a Sunday.
        $this->assertFalse($calendar->isTradingDay('US', Carbon::parse('2026-07-04', 'America/New_York')));
        $this->assertFalse($calendar->isTradingDay('US', Carbon::parse('2026-07-05', 'America/New_York')));
        $this->assertFalse($calendar->isHoliday('US', Carbon::parse('2026-07-04', 'America/New_York')));
    }

    public function test_a_normal_weekday_is_a_trading_day(): void
    {
        config(['ingestion.holidays' => ['2026-07-03']]);

        $calendar = new MarketCalendar;

        // 2026-07-06 is the Monday after the observed Independence Day holiday.
        $this->assertFalse($calendar->isHoliday('US', Carbon::parse('2026-07-06', 'America/New_York')));
        $this->assertTrue($calendar->isTradingDay('US', Carbon::parse('2026-07-06', 'America/New_York')));
    }

    public function test_a_half_day_is_still_a_trading_day(): void
    {
        $calendar = new MarketCalendar;

        // The Friday after Thanksgiving (2026-11-27) is an early close (half
        // day) and is intentionally NOT in the holiday list.
        $this->assertFalse(
            $calendar->isHoliday('US', Carbon::parse('2026-11-27', 'America/New_York')),
        );
        $this->assertTrue(
            $calendar->isTradingDay('US', Carbon::parse('2026-11-27', 'America/New_York')),
        );
    }

    public function test_the_committed_holiday_list_is_a_valid_snapshot(): void
    {
        $holidays = config('ingestion.holidays');

        $this->assertIsArray($holidays);
        $this->assertNotEmpty($holidays);
        $this->assertSame(array_values(array_unique($holidays)), array_values($holidays));

        foreach ($holidays as $holiday) {
            $this->assertIsString($holiday);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $holiday);

            // The observed-weekend rule is already applied in config, so no
            // listed holiday may fall on a weekend.
            $this->assertFalse(
                Carbon::parse($holiday, 'America/New_York')->isWeekend(),
                "{$holiday} is a listed holiday but falls on a weekend.",
            );
        }
    }

    public function test_the_committed_list_covers_the_current_and_next_year(): void
    {
        $holidays = config('ingestion.holidays');

        $this->assertContains('2026-01-01', $holidays);
        $this->assertContains('2026-07-03', $holidays);
        $this->assertContains('2027-06-18', $holidays);
        $this->assertContains('2027-12-24', $holidays);

        $years = array_values(array_unique(array_map(
            fn (string $date): string => substr($date, 0, 4),
            $holidays,
        )));
        sort($years);

        $this->assertSame(['2026', '2027'], $years);
    }

    /**
     * @param  array<int, string>  $xetrHolidays
     */
    private function configureMarkets(array $xetrHolidays = [], array $xmadHolidays = []): void
    {
        config([
            'ingestion.holidays' => ['2026-07-03'],
            'ingestion.markets.XETR.holidays' => $xetrHolidays,
            'ingestion.markets.XMAD.holidays' => $xmadHolidays,
        ]);
    }

    public function test_each_market_skips_only_its_own_holidays(): void
    {
        // 2026-07-03 is a NYSE holiday; 2026-10-03 (Saturday) is moved to a
        // fixture weekday: 2026-10-05 is a Xetra-only fixture holiday.
        $this->configureMarkets(['2026-10-05'], ['2026-12-08']);
        $calendar = new MarketCalendar;

        $this->assertFalse($calendar->isTradingDay('US', Carbon::parse('2026-07-03')));
        $this->assertTrue($calendar->isTradingDay('XETR', Carbon::parse('2026-07-03')));
        $this->assertTrue($calendar->isTradingDay('XMAD', Carbon::parse('2026-07-03')));

        $this->assertTrue($calendar->isTradingDay('US', Carbon::parse('2026-10-05')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-10-05')));
        $this->assertTrue($calendar->isTradingDay('XMAD', Carbon::parse('2026-10-05')));

        $this->assertFalse($calendar->isTradingDay('XMAD', Carbon::parse('2026-12-08')));
        $this->assertTrue($calendar->isTradingDay('XETR', Carbon::parse('2026-12-08')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-10-10'))); // Saturday
    }

    public function test_an_unknown_market_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MarketCalendar)->isTradingDay('XNAS', Carbon::parse('2026-07-06'));
    }

    public function test_latest_session_flips_at_close_plus_buffer(): void
    {
        $this->configureMarkets();
        $calendar = new MarketCalendar;

        // XETR: close 17:30 + 45 = 18:15 Europe/Berlin. 2026-07-15 is a Wednesday (CEST, UTC+2).
        $before = Carbon::parse('2026-07-15 18:14:00', 'Europe/Berlin');
        $after = Carbon::parse('2026-07-15 18:15:00', 'Europe/Berlin');

        $this->assertSame('2026-07-14', $calendar->latestSession('XETR', $before)->toDateString());
        $this->assertSame('2026-07-15', $calendar->latestSession('XETR', $after)->toDateString());

        // The same instants expressed in UTC give the same answer.
        $this->assertSame('2026-07-15', $calendar->latestSession('XETR', $after->copy()->utc())->toDateString());

        // US: close 16:00 + 30 = 16:30 New York.
        $this->assertSame('2026-07-14', $calendar->latestSession('US', Carbon::parse('2026-07-15 16:29:00', 'America/New_York'))->toDateString());
        $this->assertSame('2026-07-15', $calendar->latestSession('US', Carbon::parse('2026-07-15 16:30:00', 'America/New_York'))->toDateString());
    }

    public function test_latest_session_skips_weekends_and_own_holidays(): void
    {
        $this->configureMarkets(['2026-07-15']);
        $calendar = new MarketCalendar;

        // Monday morning: the previous session is Friday.
        $this->assertSame('2026-07-10', $calendar->latestSession('XETR', Carbon::parse('2026-07-13 09:00:00', 'Europe/Berlin'))->toDateString());
        // Saturday: still Friday.
        $this->assertSame('2026-07-10', $calendar->latestSession('XETR', Carbon::parse('2026-07-11 12:00:00', 'Europe/Berlin'))->toDateString());
        // After close on the Xetra-only holiday: the day before.
        $this->assertSame('2026-07-14', $calendar->latestSession('XETR', Carbon::parse('2026-07-15 19:00:00', 'Europe/Berlin'))->toDateString());
        // The same instant is a normal session for the US.
        $this->assertSame('2026-07-15', $calendar->latestSession('US', Carbon::parse('2026-07-15 19:00:00', 'America/New_York'))->toDateString());
    }

    public function test_latest_session_is_correct_across_the_us_and_european_dst_changes(): void
    {
        $this->configureMarkets();
        $calendar = new MarketCalendar;

        // Europe switches to summer time on Sunday 2026-03-29, the US on Sunday 2026-03-08.
        // Friday 2026-03-27: Berlin is still CET (UTC+1), New York already EDT (UTC-4).
        // 18:15 CET = 17:15 UTC: the Xetra session is ready; at 17:14 UTC it is not.
        $this->assertSame('2026-03-27', $calendar->latestSession('XETR', Carbon::parse('2026-03-27 17:15:00', 'UTC'))->toDateString());
        $this->assertSame('2026-03-26', $calendar->latestSession('XETR', Carbon::parse('2026-03-27 17:14:00', 'UTC'))->toDateString());
        // 16:30 EDT = 20:30 UTC.
        $this->assertSame('2026-03-27', $calendar->latestSession('US', Carbon::parse('2026-03-27 20:30:00', 'UTC'))->toDateString());
        $this->assertSame('2026-03-26', $calendar->latestSession('US', Carbon::parse('2026-03-27 20:29:00', 'UTC'))->toDateString());

        // Monday 2026-03-30: Berlin is CEST (UTC+2): 18:15 CEST = 16:15 UTC.
        $this->assertSame('2026-03-30', $calendar->latestSession('XMAD', Carbon::parse('2026-03-30 16:15:00', 'UTC'))->toDateString());
        $this->assertSame('2026-03-27', $calendar->latestSession('XMAD', Carbon::parse('2026-03-30 16:14:00', 'UTC'))->toDateString());

        // Autumn: the US falls back on 2026-11-01, Europe on 2026-10-25.
        // Friday 2026-10-30: Berlin CET (UTC+1), New York still EDT (UTC-4).
        $this->assertSame('2026-10-30', $calendar->latestSession('XETR', Carbon::parse('2026-10-30 17:15:00', 'UTC'))->toDateString());
        $this->assertSame('2026-10-30', $calendar->latestSession('US', Carbon::parse('2026-10-30 20:30:00', 'UTC'))->toDateString());
        // Monday 2026-11-02: both on winter time; 16:30 EST = 21:30 UTC.
        $this->assertSame('2026-11-02', $calendar->latestSession('US', Carbon::parse('2026-11-02 21:30:00', 'UTC'))->toDateString());
        $this->assertSame('2026-10-30', $calendar->latestSession('US', Carbon::parse('2026-11-02 21:29:00', 'UTC'))->toDateString());
    }

    public function test_run_time_is_close_plus_buffer(): void
    {
        $calendar = new MarketCalendar;

        $this->assertSame('16:30', $calendar->runTime('US'));
        $this->assertSame('18:15', $calendar->runTime('XMAD'));
        $this->assertSame('18:15', $calendar->runTime('XETR'));
        $this->assertSame('Europe/Madrid', $calendar->timezone('XMAD'));
    }
}
