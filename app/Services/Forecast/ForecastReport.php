<?php

namespace App\Services\Forecast;

use App\Models\ForecastPrediction;

class ForecastReport
{
    /** @return array<int, array<string, mixed>> */
    public function build(float $minimumProbability): array
    {
        $groups = ForecastPrediction::query()->get()->groupBy(
            fn (ForecastPrediction $p) => implode('|', [$p->source, $p->horizon, $p->model_version, $p->question_version])
        );

        return $groups->map(function ($predictions) use ($minimumProbability): array {
            $first = $predictions->first();
            $resolved = $predictions->whereNotNull('resolved_at');
            $sample = $resolved->count();
            $correct = 0;
            $covered = 0;
            $coveredCorrect = 0;
            $brier = 0.0;
            $buckets = [];
            foreach ($resolved as $prediction) {
                $probabilities = [
                    'up' => (float) $prediction->probability_up,
                    'down' => (float) $prediction->probability_down,
                    'flat' => (float) $prediction->probability_flat,
                ];
                arsort($probabilities);
                $leading = array_key_first($probabilities);
                $confidence = $probabilities[$leading];
                $hit = $leading === $prediction->observed_class;
                $correct += (int) $hit;
                if ($leading !== 'flat' && $confidence * 100 >= $minimumProbability) {
                    $covered++;
                    $coveredCorrect += (int) $hit;
                }
                foreach ($probabilities as $class => $probability) {
                    $brier += ($probability - (int) ($class === $prediction->observed_class)) ** 2;
                }
                $bucket = min(4, (int) floor($confidence * 5));
                $buckets[$bucket] ??= ['count' => 0, 'confidence_sum' => 0.0, 'correct' => 0];
                $buckets[$bucket]['count']++;
                $buckets[$bucket]['confidence_sum'] += $confidence;
                $buckets[$bucket]['correct'] += (int) $hit;
            }

            return [
                'source' => $first->source,
                'horizon' => $first->horizon,
                'model_version' => $first->model_version,
                'question_version' => $first->question_version,
                'sample' => $sample,
                'pending' => $predictions->count() - $sample,
                'coverage' => $sample ? $covered / $sample : null,
                'directional_accuracy' => $sample ? $correct / $sample : null,
                'covered_accuracy' => $covered ? $coveredCorrect / $covered : null,
                'brier' => $sample ? $brier / $sample : null,
                'calibration' => collect($buckets)->sortKeys()->map(fn ($bucket, $index) => [
                    'bucket' => (int) $index,
                    'count' => $bucket['count'],
                    'mean_confidence' => $bucket['confidence_sum'] / $bucket['count'],
                    'accuracy' => $bucket['correct'] / $bucket['count'],
                ])->values()->all(),
            ];
        })->values()->all();
    }
}
