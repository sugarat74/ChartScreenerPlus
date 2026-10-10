<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Services\Alerts\AlertEvaluator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evaluate every active Alert once for the current as-of date.
 *
 * Run as the last stage of `ingestion:pipeline` (only when signals succeeded)
 * or manually. A failing Alert is reported and skipped (its state unchanged);
 * the others still run and the command exits `1`.
 */
class EvaluateAlerts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'alerts:evaluate';

    /**
     * @var string
     */
    protected $description = 'Evaluate active alerts and create in-app notifications for new findings';

    public function handle(AlertEvaluator $evaluator): int
    {
        $asOf = $evaluator->asOf();

        if ($asOf === null) {
            $this->info('No stored daily bars; no alerts evaluated.');

            return self::SUCCESS;
        }

        $counts = [
            AlertEvaluator::NOTIFIED => 0,
            AlertEvaluator::BASELINE => 0,
            AlertEvaluator::UNCHANGED => 0,
            AlertEvaluator::SKIPPED => 0,
        ];
        $failed = 0;

        Alert::query()
            ->where('active', true)
            ->with(['user', 'savedScreener'])
            ->orderBy('id')
            ->each(function (Alert $alert) use ($evaluator, $asOf, &$counts, &$failed): void {
                try {
                    $counts[$evaluator->evaluate($alert, $asOf)]++;
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn("Alert {$alert->id} failed: {$exception->getMessage()}");
                }
            });

        $this->info(sprintf(
            'Alerts as of %s: %d notified, %d baseline, %d unchanged, %d already evaluated, %d failed.',
            $asOf,
            $counts[AlertEvaluator::NOTIFIED],
            $counts[AlertEvaluator::BASELINE],
            $counts[AlertEvaluator::UNCHANGED],
            $counts[AlertEvaluator::SKIPPED],
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
