<?php

namespace App\Console\Commands;

use App\Models\ForecastPrediction;
use Illuminate\Console\Command;

class ResolveForecasts extends Command
{
    protected $signature = 'forecast:resolve';

    protected $description = 'Resolve due forecasts from subsequent stored Daily Bars';

    public function handle(): int
    {
        $resolved = 0;
        ForecastPrediction::query()->whereNull('resolved_at')->whereDate('target_date', '<=', now()->toDateString())
            ->with('instrument')->chunkById(100, function ($predictions) use (&$resolved): void {
                foreach ($predictions as $prediction) {
                    $targetBar = $prediction->instrument->dailyBars()
                        ->whereDate('date', $prediction->target_date->format('Y-m-d'))->first();
                    if ($targetBar === null) {
                        continue;
                    }
                    $close = (float) $targetBar->close;
                    $reference = (float) $prediction->reference_close;
                    $prediction->update([
                        'observed_close' => $close,
                        'observed_class' => $close > $reference ? 'up' : ($close < $reference ? 'down' : 'flat'),
                        'resolved_at' => now(),
                    ]);
                    $resolved++;
                }
            });
        $this->info("Resolved {$resolved} forecast(s).");

        return self::SUCCESS;
    }
}
