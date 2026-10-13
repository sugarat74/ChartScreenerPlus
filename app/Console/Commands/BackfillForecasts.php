<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\ForecastUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillForecasts extends Command
{
    protected $signature = 'forecast:backfill {ticker} {from} {to} {--horizon=1} {--limit=20}';

    protected $description = 'Explicitly create historical Jev forecasts from stored dates (provider calls may incur cost)';

    public function handle(ForecastService $service): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $horizon = (int) $this->option('horizon');
        $limit = (int) $this->option('limit');
        if (! $this->validDate($from) || ! $this->validDate($to) || $from > $to || ! in_array($horizon, config('forecast.horizons'), true) || $limit < 1 || $limit > 100) {
            $this->error('Use ISO dates from <= to, a supported horizon and limit 1..100.');

            return self::FAILURE;
        }
        $coveredYears = array_unique(array_map(
            fn (string $holiday): string => substr($holiday, 0, 4),
            config('ingestion.holidays', []),
        ));
        if (! in_array(substr($from, 0, 4), $coveredYears, true) || ! in_array(substr($to, 0, 4), $coveredYears, true)) {
            $this->error('Date range is outside the committed NYSE holiday calendar.');

            return self::FAILURE;
        }
        $instrument = Instrument::query()->where('ticker', strtoupper((string) $this->argument('ticker')))->first();
        if ($instrument === null) {
            $this->error('Unknown instrument.');

            return self::FAILURE;
        }

        $dates = $instrument->dailyBars()->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')->limit($limit)->pluck('date');
        $created = 0;
        foreach ($dates as $date) {
            try {
                $service->predict($instrument, $horizon, 0, Carbon::parse($date)->format('Y-m-d'), 'historical');
                $created++;
            } catch (ForecastUnavailable $exception) {
                $this->warn("Skipped {$date}: {$exception->reason}");
            }
        }
        $this->call('forecast:resolve');
        $this->info("Processed {$created} historical case(s).");

        return self::SUCCESS;
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }
}
