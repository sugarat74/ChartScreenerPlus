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

        $this->assertTrue($calendar->isHoliday($date));
        $this->assertFalse($calendar->isTradingDay($date));
    }

    public function test_a_weekend_is_not_a_trading_day(): void
    {
        config(['ingestion.holidays' => []]);

        $calendar = new MarketCalendar;

        // 2026-07-04 is a Saturday, 2026-07-05 is a Sunday.
        $this->assertFalse($calendar->isTradingDay(Carbon::parse('2026-07-04', 'America/New_York')));
        $this->assertFalse($calendar->isTradingDay(Carbon::parse('2026-07-05', 'America/New_York')));
        $this->assertFalse($calendar->isHoliday(Carbon::parse('2026-07-04', 'America/New_York')));
    }

    public function test_a_normal_weekday_is_a_trading_day(): void
    {
        config(['ingestion.holidays' => ['2026-07-03']]);

        $calendar = new MarketCalendar;

        // 2026-07-06 is the Monday after the observed Independence Day holiday.
        $this->assertFalse($calendar->isHoliday(Carbon::parse('2026-07-06', 'America/New_York')));
        $this->assertTrue($calendar->isTradingDay(Carbon::parse('2026-07-06', 'America/New_York')));
    }

    public function test_a_half_day_is_still_a_trading_day(): void
    {
        $calendar = new MarketCalendar;

        // The Friday after Thanksgiving (2026-11-27) is an early close (half
        // day) and is intentionally NOT in the holiday list.
        $this->assertFalse(
            $calendar->isHoliday(Carbon::parse('2026-11-27', 'America/New_York')),
        );
        $this->assertTrue(
            $calendar->isTradingDay(Carbon::parse('2026-11-27', 'America/New_York')),
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
}
