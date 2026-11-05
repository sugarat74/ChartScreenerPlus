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
     * The regular continuous session of a trading day (market-hours-status):
     * `opens_at` / `closes_at` as instants (the early close when the day has
     * one), or `null` on a weekend or a committed holiday. `$day` is read as a
     * calendar date in the market's own timezone, so DST changes are absorbed
     * by building the wall-clock time in that zone.
     *
     * @return array{opens_at: Carbon, closes_at: Carbon, early_close: bool}|null
     */
    public function session(string $market, DateTimeInterface $day): ?array
    {
        if (! $this->isTradingDay($market, $day)) {
            return null;
        }

        $settings = $this->settings($market);
        $date = $day->format('Y-m-d');
        $early = $settings['early_closes'][$date] ?? null;
        $close = $early ?? $settings['close'];

        return [
            'opens_at' => Carbon::createFromFormat('Y-m-d H:i', "{$date} {$settings['open']}", $settings['timezone'])->utc(),
            'closes_at' => Carbon::createFromFormat('Y-m-d H:i', "{$date} {$close}", $settings['timezone'])->utc(),
            'early_close' => $early !== null,
        ];
    }

    /**
     * The regular session times (`HH:MM`, market timezone).
     *
     * @return array{open: string, close: string}
     */
    public function regularHours(string $market): array
    {
        $settings = $this->settings($market);

        return ['open' => $settings['open'], 'close' => $settings['close']];
    }

    /**
     * Whether the committed holiday list covers `$year`: at least one entry
     * dated in that year. A year with none is unknown, not holiday-free.
     */
    public function coversYear(string $market, int $year): bool
    {
        foreach ($this->holidays($market) as $holiday) {
            if (str_starts_with($holiday, $year.'-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Committed holidays on weekdays and early closes from `$from` (a market
     * local date, inclusive) on, soonest first, at most `$limit`.
     *
     * @return list<array{date: string, type: string, close: ?string}>
     */
    public function upcomingEvents(string $market, string $from, int $limit): array
    {
        $settings = $this->settings($market);
        $events = [];

        foreach ($settings['holidays'] as $date) {
            $weekday = (int) Carbon::parse($date)->format('N') < 6;
            if ($date >= $from && $weekday) {
                $events[$date] = ['date' => $date, 'type' => 'holiday', 'close' => null];
            }
        }

        foreach ($settings['early_closes'] as $date => $close) {
            $date = (string) $date;
            if ($date >= $from && ! isset($events[$date]) && $this->isTradingDay($market, Carbon::parse($date))) {
                $events[$date] = ['date' => $date, 'type' => 'early_close', 'close' => (string) $close];
            }
        }

        ksort($events);

        return array_slice(array_values($events), 0, $limit);
    }

    /**
     * @return array{timezone: string, open: string, close: string, buffer_minutes: int, holidays: list<string>, early_closes: array<string, string>}
     */
    private function settings(string $market): array
    {
        if ($market === ListingMarket::US) {
            return [
                'timezone' => (string) config('ingestion.timezone'),
                'open' => (string) config('ingestion.market_open', '09:30'),
                'close' => (string) config('ingestion.market_close'),
                'buffer_minutes' => (int) config('ingestion.schedule_buffer_minutes'),
                'holidays' => array_values((array) config('ingestion.holidays', [])),
                'early_closes' => (array) config('ingestion.early_closes', []),
            ];
        }

        $config = config("ingestion.markets.{$market}");

        if (! ListingMarket::isValid($market) || ! is_array($config)) {
            throw new InvalidArgumentException("Unknown listing market [{$market}].");
        }

        return [
            'timezone' => (string) ($config['timezone'] ?? ListingMarket::timezone($market)),
            'open' => (string) ($config['open'] ?? ListingMarket::open($market)),
            'close' => (string) ($config['close'] ?? ListingMarket::close($market)),
            'buffer_minutes' => (int) ($config['buffer_minutes'] ?? 45),
            'holidays' => array_values((array) ($config['holidays'] ?? [])),
            'early_closes' => (array) ($config['early_closes'] ?? []),
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
