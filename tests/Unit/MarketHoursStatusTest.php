<?php

namespace Tests\Unit;

use App\Services\Market\MarketCalendar;
use App\Services\Market\MarketHoursStatus;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * market-hours-status: open/closed from the committed schedule and a frozen
 * clock. Every instant is UTC so the tests do not depend on the host timezone.
 */
class MarketHoursStatusTest extends TestCase
{
    private function at(string $market, string $utc): array
    {
        return (new MarketHoursStatus(new MarketCalendar))
            ->forMarket($market, Carbon::parse($utc, 'UTC'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function boundaries(): array
    {
        return [
            // US summer (EDT = UTC-4): 13:30Z to 20:00Z.
            'us just before open' => ['US', '2026-07-06 13:29:59', false],
            'us at open' => ['US', '2026-07-06 13:30:00', true],
            'us just before close' => ['US', '2026-07-06 19:59:59', true],
            'us at close' => ['US', '2026-07-06 20:00:00', false],
            // US winter (EST = UTC-5): 14:30Z to 21:00Z.
            'us winter before open' => ['US', '2026-01-12 14:29:59', false],
            'us winter at open' => ['US', '2026-01-12 14:30:00', true],
            'us winter at close' => ['US', '2026-01-12 21:00:00', false],
            // Madrid summer (CEST = UTC+2): 07:00Z to 15:30Z.
            'madrid before open' => ['XMAD', '2026-07-06 06:59:59', false],
            'madrid at open' => ['XMAD', '2026-07-06 07:00:00', true],
            'madrid before close' => ['XMAD', '2026-07-06 15:29:59', true],
            'madrid at close' => ['XMAD', '2026-07-06 15:30:00', false],
            // Xetra winter (CET = UTC+1): 08:00Z to 16:30Z.
            'xetra winter before open' => ['XETR', '2026-01-12 07:59:59', false],
            'xetra winter at open' => ['XETR', '2026-01-12 08:00:00', true],
            'xetra winter before close' => ['XETR', '2026-01-12 16:29:59', true],
            'xetra winter at close' => ['XETR', '2026-01-12 16:30:00', false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_open_and_close_boundaries(string $market, string $utc, bool $open): void
    {
        $this->assertSame($open, $this->at($market, $utc)['is_open']);
    }

    public function test_weekend_is_closed_and_the_next_open_is_monday(): void
    {
        // Saturday 2026-07-04 12:00 UTC.
        $us = $this->at('US', '2026-07-04 12:00:00');
        $this->assertFalse($us['is_open']);
        $this->assertNull($us['opens_at']);
        $this->assertSame('2026-07-06T13:30:00Z', $us['next_open_at']);
        $this->assertSame('2026-07-06T20:00:00Z', $us['next_close_at']);

        $eu = $this->at('XETR', '2026-07-05 09:00:00');
        $this->assertFalse($eu['is_open']);
        $this->assertSame('2026-07-06T07:00:00Z', $eu['next_open_at']);
    }

    public function test_each_market_uses_its_own_holidays(): void
    {
        // Friday 2026-05-01: Labour Day closes Madrid and Xetra, not the US.
        $this->assertTrue($this->at('US', '2026-05-01 15:00:00')['is_open']);
        $this->assertFalse($this->at('XMAD', '2026-05-01 10:00:00')['is_open']);
        $this->assertNull($this->at('XETR', '2026-05-01 10:00:00')['opens_at']);

        // Monday 2026-09-07 is Labor Day in the US only.
        $this->assertFalse($this->at('US', '2026-09-07 15:00:00')['is_open']);
        $this->assertTrue($this->at('XETR', '2026-09-07 10:00:00')['is_open']);

        // Xetra is closed on 24 December while Madrid trades a half day.
        $this->assertFalse($this->at('XETR', '2026-12-24 10:00:00')['is_open']);
        $this->assertTrue($this->at('XMAD', '2026-12-24 10:00:00')['is_open']);
    }

    public function test_paris_has_its_own_hours_holidays_and_half_days(): void
    {
        // Summer (CEST): 09:00 to 17:30 Paris = 07:00Z to 15:30Z.
        $this->assertFalse($this->at('XPAR', '2026-07-06 06:59:59')['is_open']);
        $this->assertTrue($this->at('XPAR', '2026-07-06 07:00:00')['is_open']);
        $this->assertTrue($this->at('XPAR', '2026-07-06 15:29:59')['is_open']);
        $this->assertFalse($this->at('XPAR', '2026-07-06 15:30:00')['is_open']);
        // Winter (CET): 08:00Z to 16:30Z.
        $this->assertTrue($this->at('XPAR', '2026-01-12 08:00:00')['is_open']);
        $this->assertFalse($this->at('XPAR', '2026-01-12 16:30:00')['is_open']);

        // Easter Monday and 1 May are closed in Paris; 25 May (UK bank holiday) is open.
        $this->assertFalse($this->at('XPAR', '2026-04-06 10:00:00')['is_open']);
        $this->assertFalse($this->at('XPAR', '2026-05-01 10:00:00')['is_open']);
        $this->assertTrue($this->at('XPAR', '2026-05-25 10:00:00')['is_open']);

        // 24 December 2026: half day closing at 14:05 CET = 13:05Z.
        $half = $this->at('XPAR', '2026-12-24 13:04:59');
        $this->assertTrue($half['is_open']);
        $this->assertTrue($half['early_close']);
        $this->assertSame('2026-12-24T13:05:00Z', $half['closes_at']);
        $this->assertFalse($this->at('XPAR', '2026-12-24 13:05:00')['is_open']);
        $this->assertFalse($this->at('XPAR', '2026-12-25 10:00:00')['is_open']);
    }

    public function test_london_has_its_own_hours_bank_holidays_and_half_days(): void
    {
        // Summer (BST): 08:00 to 16:30 London = 07:00Z to 15:30Z.
        $this->assertFalse($this->at('XLON', '2026-07-06 06:59:59')['is_open']);
        $this->assertTrue($this->at('XLON', '2026-07-06 07:00:00')['is_open']);
        $this->assertTrue($this->at('XLON', '2026-07-06 15:29:59')['is_open']);
        $this->assertFalse($this->at('XLON', '2026-07-06 15:30:00')['is_open']);
        // Winter (GMT): 08:00Z to 16:30Z.
        $this->assertTrue($this->at('XLON', '2026-01-12 08:00:00')['is_open']);
        $this->assertFalse($this->at('XLON', '2026-01-12 16:30:00')['is_open']);

        // UK bank holidays are closed in London only; 1 May is a London trading day.
        $this->assertFalse($this->at('XLON', '2026-05-25 10:00:00')['is_open']);
        $this->assertTrue($this->at('XPAR', '2026-05-25 10:00:00')['is_open']);
        $this->assertTrue($this->at('XLON', '2026-05-01 10:00:00')['is_open']);
        $this->assertFalse($this->at('XPAR', '2026-05-01 10:00:00')['is_open']);
        $this->assertFalse($this->at('XLON', '2026-12-28 10:00:00')['is_open']);

        // 31 December 2026: half day closing at 12:30 GMT.
        $half = $this->at('XLON', '2026-12-31 12:29:59');
        $this->assertTrue($half['is_open']);
        $this->assertTrue($half['early_close']);
        $this->assertSame('2026-12-31T12:30:00Z', $half['closes_at']);
        $this->assertFalse($this->at('XLON', '2026-12-31 12:30:00')['is_open']);
    }

    public function test_early_closes_shorten_the_session(): void
    {
        // US, day after Thanksgiving 2026: 09:30 EST to 13:00 EST = 14:30Z to 18:00Z.
        $us = $this->at('US', '2026-11-27 17:59:59');
        $this->assertTrue($us['is_open']);
        $this->assertTrue($us['early_close']);
        $this->assertSame('2026-11-27T18:00:00Z', $us['closes_at']);
        $this->assertFalse($this->at('US', '2026-11-27 18:00:00')['is_open']);

        // Madrid, 24 December 2026: closes 14:00 CET = 13:00Z.
        $this->assertTrue($this->at('XMAD', '2026-12-24 12:59:59')['is_open']);
        $this->assertFalse($this->at('XMAD', '2026-12-24 13:00:00')['is_open']);

        $this->assertFalse($this->at('US', '2026-11-30 12:00:00')['early_close']);
    }

    public function test_us_dst_changes_move_the_utc_instants(): void
    {
        // Spring forward 2026-03-08: Friday 03-06 opens 14:30Z, Monday 03-09 13:30Z.
        $this->assertSame('2026-03-06T14:30:00Z', $this->at('US', '2026-03-06 09:00:00')['opens_at']);
        $this->assertSame('2026-03-09T13:30:00Z', $this->at('US', '2026-03-09 09:00:00')['opens_at']);
        $this->assertFalse($this->at('US', '2026-03-09 13:29:59')['is_open']);
        $this->assertTrue($this->at('US', '2026-03-09 13:30:00')['is_open']);

        // Fall back 2026-11-01: Friday 10-30 opens 13:30Z, Monday 11-02 14:30Z.
        $this->assertSame('2026-10-30T13:30:00Z', $this->at('US', '2026-10-30 09:00:00')['opens_at']);
        $this->assertSame('2026-11-02T14:30:00Z', $this->at('US', '2026-11-02 09:00:00')['opens_at']);
        $this->assertFalse($this->at('US', '2026-11-02 14:29:59')['is_open']);
        $this->assertTrue($this->at('US', '2026-11-02 14:30:00')['is_open']);
    }

    public function test_european_dst_changes_fall_in_different_weeks_than_the_us(): void
    {
        // Spring forward 2026-03-29: Madrid Friday 03-27 opens 08:00Z, Monday 03-30 07:00Z.
        $this->assertSame('2026-03-27T08:00:00Z', $this->at('XMAD', '2026-03-27 06:00:00')['opens_at']);
        $this->assertSame('2026-03-30T07:00:00Z', $this->at('XMAD', '2026-03-30 06:00:00')['opens_at']);
        // The US had already moved (03-08).
        $this->assertSame('2026-03-27T13:30:00Z', $this->at('US', '2026-03-27 06:00:00')['opens_at']);

        // Fall back 2026-10-25: Xetra Friday 10-23 opens 07:00Z, Monday 10-26 08:00Z.
        $this->assertSame('2026-10-23T07:00:00Z', $this->at('XETR', '2026-10-23 05:00:00')['opens_at']);
        $this->assertSame('2026-10-26T08:00:00Z', $this->at('XETR', '2026-10-26 05:00:00')['opens_at']);
        // The US is still on EDT that Monday.
        $this->assertSame('2026-10-26T13:30:00Z', $this->at('US', '2026-10-26 05:00:00')['opens_at']);
    }

    public function test_next_open_skips_a_weekend_plus_holidays(): void
    {
        // Xetra after Thursday 2026-04-02's close: Good Friday and Easter Monday are
        // holidays, so the next open is Tuesday 04-07 09:00 CEST = 07:00Z.
        $status = $this->at('XETR', '2026-04-02 16:00:00');
        $this->assertFalse($status['is_open']);
        $this->assertSame('2026-04-07T07:00:00Z', $status['next_open_at']);
        $this->assertSame('2026-04-07T15:30:00Z', $status['next_close_at']);

        // US: Good Friday 2026-04-03 only, so Thursday evening reopens Monday 04-06.
        $this->assertSame('2026-04-06T13:30:00Z', $this->at('US', '2026-04-02 21:00:00')['next_open_at']);
    }

    public function test_open_market_reports_its_close_and_the_following_open(): void
    {
        $status = $this->at('US', '2026-07-06 15:00:00');
        $this->assertTrue($status['is_open']);
        $this->assertSame('2026-07-06T20:00:00Z', $status['next_close_at']);
        $this->assertSame('2026-07-07T13:30:00Z', $status['next_open_at']);
    }

    public function test_the_local_date_follows_the_market_timezone(): void
    {
        // 2026-07-07 01:00Z is still Monday evening in New York: the session is Monday's.
        $status = $this->at('US', '2026-07-07 01:00:00');
        $this->assertSame('2026-07-06T13:30:00Z', $status['opens_at']);
        $this->assertFalse($status['is_open']);
        $this->assertSame('2026-07-07T13:30:00Z', $status['next_open_at']);
    }

    public function test_upcoming_lists_holidays_and_early_closes_in_order(): void
    {
        $upcoming = $this->at('XMAD', '2026-12-01 10:00:00')['upcoming'];

        $this->assertSame([
            ['date' => '2026-12-24', 'type' => 'early_close', 'close' => '14:00'],
            ['date' => '2026-12-25', 'type' => 'holiday', 'close' => null],
            ['date' => '2026-12-31', 'type' => 'early_close', 'close' => '14:00'],
            ['date' => '2027-01-01', 'type' => 'holiday', 'close' => null],
            ['date' => '2027-03-26', 'type' => 'holiday', 'close' => null],
            ['date' => '2027-03-29', 'type' => 'holiday', 'close' => null],
        ], $upcoming);
    }

    public function test_a_year_without_committed_holidays_is_flagged_not_assumed_holiday_free(): void
    {
        $covered = $this->at('XETR', '2026-07-06 10:00:00');
        $this->assertTrue($covered['calendar_covered']);
        $this->assertSame(2026, $covered['calendar_year']);

        // 2030 has no committed list: weekdays still work, the flag warns.
        $uncovered = $this->at('XETR', '2030-07-08 10:00:00');
        $this->assertFalse($uncovered['calendar_covered']);
        $this->assertTrue($uncovered['is_open']);
        $this->assertSame([], $uncovered['upcoming']);
    }
}
