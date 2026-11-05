<?php

namespace App\Services\Market;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Open/closed status of a Listing Market computed only from the committed
 * schedule (`MarketCalendar`) and the clock passed in (market-hours-status).
 * No I/O and no market data: an unscheduled halt or closure is not reflected.
 * Only the regular continuous session is modelled (no auctions, no
 * pre/post-market). Every instant is UTC ISO-8601; the SPA formats them in the
 * viewer's timezone.
 */
class MarketHoursStatus
{
    /** How many holidays and early closes the payload lists. */
    public const UPCOMING_LIMIT = 6;

    /** Bounded look-ahead for the next session (no calendar closes this long). */
    private const LOOKAHEAD_DAYS = 14;

    public function __construct(private readonly MarketCalendar $calendar) {}

    /**
     * @return array{
     *     code: string,
     *     timezone: string,
     *     regular_hours: array{open: string, close: string},
     *     is_open: bool,
     *     opens_at: ?string,
     *     closes_at: ?string,
     *     early_close: bool,
     *     next_open_at: ?string,
     *     next_close_at: ?string,
     *     calendar_year: int,
     *     calendar_covered: bool,
     *     upcoming: list<array{date: string, type: string, close: ?string}>
     * }
     */
    public function forMarket(string $market, DateTimeInterface $now): array
    {
        $timezone = $this->calendar->timezone($market);
        $instant = Carbon::instance($now)->utc();
        $today = $instant->copy()->setTimezone($timezone)->startOfDay();

        $session = $this->calendar->session($market, $today);
        $isOpen = $session !== null
            && $instant->gte($session['opens_at'])
            && $instant->lt($session['closes_at']);

        // The first session whose open is still ahead, and the close that
        // matters next: the running session's, otherwise the next session's.
        $next = null;
        for ($offset = 0; $offset <= self::LOOKAHEAD_DAYS && $next === null; $offset++) {
            $candidate = $this->calendar->session($market, $today->copy()->addDays($offset));
            if ($candidate !== null && $candidate['opens_at']->gt($instant)) {
                $next = $candidate;
            }
        }

        $nextClose = $isOpen ? $session['closes_at'] : ($next['closes_at'] ?? null);

        return [
            'code' => $market,
            'timezone' => $timezone,
            'regular_hours' => $this->calendar->regularHours($market),
            'is_open' => $isOpen,
            'opens_at' => $session === null ? null : $session['opens_at']->toIso8601ZuluString(),
            'closes_at' => $session === null ? null : $session['closes_at']->toIso8601ZuluString(),
            'early_close' => $session !== null && $session['early_close'],
            'next_open_at' => $next === null ? null : $next['opens_at']->toIso8601ZuluString(),
            'next_close_at' => $nextClose?->toIso8601ZuluString(),
            'calendar_year' => (int) $today->format('Y'),
            'calendar_covered' => $this->calendar->coversYear($market, (int) $today->format('Y')),
            'upcoming' => $this->calendar->upcomingEvents($market, $today->format('Y-m-d'), self::UPCOMING_LIMIT),
        ];
    }
}
