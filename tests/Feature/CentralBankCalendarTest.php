<?php

namespace Tests\Feature;

use App\Services\Macro\CentralBankCalendar;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The hand-kept Fed/ECB meeting calendar (docs/specs/macro-series-fred.md,
 * Domain Rule 7, Scenario 7).
 */
class CentralBankCalendarTest extends TestCase
{
    private function calendar(): CentralBankCalendar
    {
        return app(CentralBankCalendar::class);
    }

    public function test_the_config_dates_are_valid_ascending_and_two_day_meetings(): void
    {
        $this->assertSame(['fed', 'ecb'], $this->calendar()->banks());

        foreach (['fed', 'ecb'] as $bank) {
            $config = config("central_banks.{$bank}");
            $this->assertNotEmpty($config['name']);
            $this->assertStringStartsWith('https://', $config['source_url']);
            $this->assertSame('2026-10-22', $config['checked_at']);

            $previous = null;
            foreach ($config['meetings'] as $meeting) {
                $start = Carbon::createFromFormat('!Y-m-d', $meeting['start']);
                $decision = Carbon::createFromFormat('!Y-m-d', $meeting['decision']);
                $this->assertSame($meeting['start'], $start->toDateString(), "{$bank}: invalid start {$meeting['start']}");
                $this->assertSame($meeting['decision'], $decision->toDateString(), "{$bank}: invalid decision {$meeting['decision']}");
                $this->assertTrue($start->lte($decision) && $decision->lte($start->copy()->addDay()), "{$bank}: {$meeting['start']} must be the day of or the day before {$meeting['decision']}");
                if ($previous !== null) {
                    $this->assertGreaterThan($previous, $meeting['decision'], "{$bank}: meetings must be ascending");
                }
                $previous = $meeting['decision'];
            }
            // Config order is already ascending: the service keeps it.
            $this->assertSame(array_values($config['meetings']), $this->calendar()->meetings($bank));
        }
    }

    public function test_next_and_last_around_a_decision_day(): void
    {
        $calendar = $this->calendar();

        // On the decision day the meeting is still the next one.
        $this->assertSame(['start' => '2026-10-27', 'decision' => '2026-10-28'], $calendar->next('fed', '2026-10-28'));
        $this->assertSame(['start' => '2026-09-15', 'decision' => '2026-09-16'], $calendar->last('fed', '2026-10-28'));
        // The day after, it becomes the last one.
        $this->assertSame(['start' => '2026-12-08', 'decision' => '2026-12-09'], $calendar->next('fed', '2026-10-29'));
        $this->assertSame(['start' => '2026-10-27', 'decision' => '2026-10-28'], $calendar->last('fed', '2026-10-29'));

        $this->assertSame(['start' => '2026-10-28', 'decision' => '2026-10-29'], $calendar->next('ecb', '2026-10-22'));
        $this->assertSame(['start' => '2026-09-09', 'decision' => '2026-09-10'], $calendar->last('ecb', '2026-10-22'));
    }

    public function test_no_meeting_outside_the_config_is_guessed(): void
    {
        $calendar = $this->calendar();

        $this->assertNull($calendar->last('fed', '2026-01-28'));
        $this->assertNull($calendar->next('ecb', '2027-12-17'));
        $this->assertSame(['start' => '2027-12-15', 'decision' => '2027-12-16'], $calendar->last('ecb', '2027-12-17'));

        $this->expectException(InvalidArgumentException::class);
        $calendar->next('boe', '2026-10-22');
    }

    /**
     * Real clock on purpose: a reminder to add the next year's meetings to
     * config/central_banks.php before either bank runs out of dates.
     */
    public function test_each_bank_has_a_decision_at_least_60_days_ahead(): void
    {
        $horizon = Carbon::today()->addDays(60)->toDateString();

        foreach (['fed', 'ecb'] as $bank) {
            $this->assertNotNull(
                $this->calendar()->next($bank, $horizon),
                "config/central_banks.php has no {$bank} decision on or after {$horizon}: add next year's official dates.",
            );
        }
    }
}
