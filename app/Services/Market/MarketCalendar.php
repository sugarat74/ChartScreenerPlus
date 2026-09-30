<?php

namespace App\Services\Market;

use DateTimeInterface;

/**
 * Pure market-calendar helper: decides whether a date is a trading day from the
 * committed NYSE holiday list in `config/ingestion.php`.
 *
 * It has no I/O and no clock of its own: callers pass the date (typically
 * `now(config('ingestion.timezone'))`), which keeps the behavior deterministic
 * and directly testable with Carbon's test clock.
 */
class MarketCalendar
{
    /**
     * Whether the date is a committed NYSE market holiday.
     */
    public function isHoliday(DateTimeInterface $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holidays(), true);
    }

    /**
     * Whether the date is a trading day: not a weekend and not a holiday.
     * Half-days (early closes) are trading days and are not skipped.
     */
    public function isTradingDay(DateTimeInterface $date): bool
    {
        return ! $this->isWeekend($date) && ! $this->isHoliday($date);
    }

    /**
     * ISO-8601 weekday number: 6 = Saturday, 7 = Sunday.
     */
    private function isWeekend(DateTimeInterface $date): bool
    {
        return (int) $date->format('N') >= 6;
    }

    /**
     * The committed holiday list (Y-m-d strings).
     *
     * @return array<int, string>
     */
    private function holidays(): array
    {
        return config('ingestion.holidays', []);
    }
}
