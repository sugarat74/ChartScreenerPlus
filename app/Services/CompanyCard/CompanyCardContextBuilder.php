<?php

namespace App\Services\CompanyCard;

use App\Models\ChartPattern;
use App\Models\Instrument;
use App\Models\Signal;

/**
 * The only data that leaves the server for a Company Card.
 *
 * Always the Instrument identity (public). Our stored technical context
 * (latest close, change, indicators, Signals, chartist patterns) is added only
 * when `config('ai_card.rights_verified')` is true, the same market-data
 * rights caution as Jev. Never any user, session, Watchlist or Saved Screener
 * data.
 */
class CompanyCardContextBuilder
{
    public const MODE_IDENTITY = 'identity';

    public const MODE_TECHNICAL = 'identity+technical';

    private const INDICATORS = ['sma20', 'sma50', 'sma200', 'rsi14', 'rvol', 'macd_hist', 'adx'];

    /**
     * @return array{context: array<string, mixed>, mode: string, as_of_date: string|null}
     */
    public function build(Instrument $instrument): array
    {
        $context = [
            'instrument' => [
                'ticker' => $instrument->ticker,
                'company' => $instrument->company,
                'sector' => $instrument->sector,
                'exchange' => $instrument->exchange,
            ],
        ];

        if (! config('ai_card.rights_verified')) {
            return ['context' => $context, 'mode' => self::MODE_IDENTITY, 'as_of_date' => null];
        }

        $bars = $instrument->dailyBars()->orderByDesc('date')->limit(2)->get();
        $latest = $bars->first();
        if ($latest === null) {
            return ['context' => $context, 'mode' => self::MODE_IDENTITY, 'as_of_date' => null];
        }

        $date = $latest->date->format('Y-m-d');
        $close = (float) $latest->close;
        $previous = $bars->get(1);
        $change = $previous !== null && (float) $previous->close > 0
            ? round(($close / (float) $previous->close - 1) * 100, 2)
            : null;

        $snapshot = $instrument->indicatorSnapshots()->whereDate('date', $date)->first();
        $indicators = [];
        foreach (self::INDICATORS as $field) {
            $value = $snapshot?->{$field};
            $indicators[$field] = $value === null ? null : round((float) $value, 4);
        }

        $context['technical'] = [
            'as_of_date' => $date,
            'close' => $close,
            'change_percent' => $change,
            'indicators' => $indicators,
            'signals' => $instrument->signals()->orderBy('type')->get()
                ->map(fn (Signal $signal): string => $signal->type)->values()->all(),
            'chart_patterns' => $instrument->chartPatterns()->orderBy('type')->get()
                ->map(fn (ChartPattern $pattern): array => [
                    'type' => $pattern->type,
                    'status' => $pattern->status,
                    'breakout_level' => $pattern->breakout_level,
                ])->values()->all(),
        ];

        return ['context' => $context, 'mode' => self::MODE_TECHNICAL, 'as_of_date' => $date];
    }
}
