<?php

namespace App\Services\Market;

use App\Support\ListingMarket;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Pure per-Listing-Market calendar helper: decides whether a date is a trading
 * day from the committed holiday lists in `config/ingestion.php` and finds the
 * latest session whose close plus buffer has passed.
 *
 * `US` is configured by the legacy top-level keys (`timezone`, `market_close`,
 * `schedule_buffer_minutes`, `holidays`); every other market by
 * `ingestion.markets.<code>`. There is no I/O and no clock of its own: callers
 * pass the date or instant, which keeps the behavior deterministic and directly
 * testable with Carbon's test clock.
 */
class MarketCalendar
{
    /**
     * Whether the date is a committed market holiday.
     */
    public function isHoliday(string $market, DateTimeInterface $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holidays($market), true);
    }

    /**
     * Whether the date is a trading day: not a weekend and not a holiday.
     * Half-days (early closes) are trading days and are not skipped.
     */
    public function isTradingDay(string $market, DateTimeInterface $date): bool
    {
        return ! $this->isWeekend($date) && ! $this->isHoliday($market, $date);
    }

    /**
     * The most recent trading day (midnight, market timezone) whose close plus
     * buffer has already passed at `$now`.
     */
    public function latestSession(string $market, DateTimeInterface $now): Carbon
    {
        $settings = $this->settings($market);
        $local = Carbon::instance($now)->setTimezone($settings['timezone']);

        $day = $local->copy()->startOfDay();
        $readyAt = $day->copy()->setTimeFromTimeString($settings['close'])->addMinutes($settings['buffer_minutes']);

        if ($local->lt($readyAt)) {
            $day->subDay();
        }

        // Bounded: no real calendar has a month without a trading day.
        for ($guard = 0; $guard < 31 && ! $this->isTradingDay($market, $day); $guard++) {
            $day->subDay();
        }

        return $day;
    }

    /**
     * The `HH:MM` wall-clock time (market timezone) at which the market's
     * pipeline is scheduled: its close plus the buffer.
     */
    public function runTime(string $market): string
    {
        $settings = $this->settings($market);

        return Carbon::createFromFormat('H:i', $settings['close'], $settings['timezone'])
            ->addMinutes($settings['buffer_minutes'])
            ->format('H:i');
    }

    /**
     * The market's IANA timezone.
     */
    public function timezone(string $market): string
    {
        return $this->settings($market)['timezone'];
    }

    /**
     * @return array{timezone: string, close: string, buffer_minutes: int, holidays: list<string>}
     */
    private function settings(string $market): array
    {
        if ($market === ListingMarket::US) {
            return [
                'timezone' => (string) config('ingestion.timezone'),
                'close' => (string) config('ingestion.market_close'),
                'buffer_minutes' => (int) config('ingestion.schedule_buffer_minutes'),
                'holidays' => array_values((array) config('ingestion.holidays', [])),
            ];
        }

        $config = config("ingestion.markets.{$market}");

        if (! ListingMarket::isValid($market) || ! is_array($config)) {
            throw new InvalidArgumentException("Unknown listing market [{$market}].");
        }

        return [
            'timezone' => (string) ($config['timezone'] ?? ListingMarket::timezone($market)),
            'close' => (string) ($config['close'] ?? ListingMarket::close($market)),
            'buffer_minutes' => (int) ($config['buffer_minutes'] ?? 45),
            'holidays' => array_values((array) ($config['holidays'] ?? [])),
        ];
    }

    /**
     * The committed holiday list (Y-m-d strings).
     *
     * @return list<string>
     */
    private function holidays(string $market): array
    {
        return $this->settings($market)['holidays'];
    }

    /**
     * ISO-8601 weekday number: 6 = Saturday, 7 = Sunday.
     */
    private function isWeekend(DateTimeInterface $date): bool
    {
        return (int) $date->format('N') >= 6;
    }
}
