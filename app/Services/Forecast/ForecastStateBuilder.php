<?php

namespace App\Services\Forecast;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use App\Services\Market\MarketCalendar;
use Illuminate\Support\Carbon;

class ForecastStateBuilder
{
    public function __construct(private readonly MarketCalendar $calendar) {}

    /**
     * Build only from records available at the chosen close. Historical calls
     * never read today's active Signals when their date is later than as-of.
     *
     * @return array{as_of_date: string, reference_close: float, state: array<string, mixed>, input_hash: string}|null
     */
    public function build(Instrument $instrument, ?string $asOfDate = null): ?array
    {
        $latest = $instrument->dailyBars()
            ->when($asOfDate !== null, fn ($query) => $query->whereDate('date', '<=', $asOfDate))
            ->orderByDesc('date')->first();

        if ($latest === null || (float) $latest->close <= 0) {
            return null;
        }

        $date = $latest->date->format('Y-m-d');
        $bars = $instrument->dailyBars()->whereDate('date', '<=', $date)
            ->orderByDesc('date')->limit((int) config('forecast.history_bars'))->get()
            ->reverse()->values();

        if ($bars->count() < (int) config('forecast.minimum_bars')) {
            return null;
        }

        $snapshot = $instrument->indicatorSnapshots()->whereDate('date', $date)->first();
        if ($snapshot === null || $snapshot->rsi14 === null || $snapshot->sma50 === null) {
            return null;
        }

        // Signals are an active set, replaced on each detection. A past cut
        // without retained Signals truthfully carries an empty set.
        $signals = $instrument->signals()->whereDate('date', $date)->orderBy('type')->get();
        $state = [
            'ticker' => $instrument->ticker,
            'as_of_date' => $date,
            'reference_close' => (float) $latest->close,
            'bars' => $bars->map(fn (DailyBar $bar): array => [
                'date' => $bar->date->format('Y-m-d'),
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (int) $bar->volume,
            ])->all(),
            'indicators' => $this->indicators($snapshot),
            'signals' => $signals->map(fn (Signal $signal): array => [
                'type' => $signal->type,
                'metadata' => $signal->metadata,
            ])->all(),
        ];

        return [
            'as_of_date' => $date,
            'reference_close' => (float) $latest->close,
            'state' => $state,
            'input_hash' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
        ];
    }

    public function targetDate(string $asOfDate, int $horizon): string
    {
        $date = Carbon::parse($asOfDate, (string) config('ingestion.timezone'));
        $coveredYears = array_fill_keys(array_map(
            fn (string $holiday): string => substr($holiday, 0, 4),
            config('ingestion.holidays', []),
        ), true);
        $sessions = 0;
        while ($sessions < $horizon) {
            if (! isset($coveredYears[$date->format('Y')])) {
                throw new ForecastUnavailable('calendar_unavailable');
            }
            $date->addDay();
            if (! isset($coveredYears[$date->format('Y')])) {
                throw new ForecastUnavailable('calendar_unavailable');
            }
            if ($this->calendar->isTradingDay($date)) {
                $sessions++;
            }
        }

        return $date->format('Y-m-d');
    }

    /** @return array<string, float|null> */
    private function indicators(IndicatorSnapshot $snapshot): array
    {
        $fields = [
            'sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx',
            'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle',
            'bb_lower', 'rvol',
        ];
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $snapshot->{$field} === null ? null : (float) $snapshot->{$field};
        }

        return $result;
    }
}
