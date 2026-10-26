<?php

namespace App\Services\Market;

use App\Models\Universe;
use App\Support\ListingMarket;
use Illuminate\Console\Scheduling\Schedule;
use Throwable;

/**
 * Registers one `ingestion:pipeline` event per Listing Market: weekdays at the
 * market's close plus buffer, in the market's own timezone (DST-aware), skipping
 * the market's own holidays. `US` is always scheduled (its run is the pipeline
 * the app has always had); another market only when at least one Screener
 * universe of it is seeded, so an install without it schedules nothing for it.
 *
 * The `ingestion:pipeline` lock is global, so two events at the same time never
 * run stages concurrently: the later one waits for the lock.
 */
class PipelineSchedule
{
    public function __construct(private readonly MarketCalendar $calendar) {}

    public function register(Schedule $schedule): void
    {
        foreach ($this->markets() as $market) {
            $timezone = $this->calendar->timezone($market);

            $schedule->command('ingestion:pipeline', ['--market' => $market])
                ->name($market === ListingMarket::US ? 'ingestion-pipeline' : 'ingestion-pipeline-'.strtolower($market))
                ->dailyAt($this->calendar->runTime($market))
                ->timezone($timezone)
                ->weekdays()
                ->skip(fn (): bool => $this->calendar->isHoliday($market, now($timezone)))
                ->withoutOverlapping(120);
        }
    }

    /**
     * The Listing Markets to schedule, in the closed list's order.
     *
     * @return list<string>
     */
    private function markets(): array
    {
        $seeded = $this->seededMarkets();

        return array_values(array_filter(
            ListingMarket::codes(),
            fn (string $market): bool => $market === ListingMarket::US || in_array($market, $seeded, true),
        ));
    }

    /**
     * Listing Markets that have a seeded Screener universe. The console routes
     * load before migrations exist (fresh install, `migrate`), so a database
     * that cannot be read means none.
     *
     * @return list<string>
     */
    private function seededMarkets(): array
    {
        try {
            return Universe::query()
                ->where('is_screener', true)
                ->distinct()
                ->pluck('market')
                ->map(fn (mixed $market): string => (string) $market)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
