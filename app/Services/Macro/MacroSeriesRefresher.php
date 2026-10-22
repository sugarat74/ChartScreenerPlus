<?php

namespace App\Services\Macro;

use App\Models\MacroObservation;
use App\Models\MacroSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refresh one Macro Series from FRED (docs/specs/macro-series-fred.md,
 * Domain Rule 4).
 *
 * Window: with no stored observation (or `backfill`) the last
 * `backfill_years`; otherwise from the last stored date minus
 * `refetch_days[frequency]`, so revisions are picked up. New observations are
 * inserted, revised values updated and dates FRED now returns as `"."`
 * deleted, all in one transaction with the series' refresh state. A second
 * identical run writes no observation. On failure only `last_attempted_at`
 * and `last_error` (a code) change; earlier data is kept.
 */
class MacroSeriesRefresher
{
    public const RESULT_UPDATED = 'updated';

    public const RESULT_FAILED = 'failed';

    private const CHUNK = 500;

    public function __construct(private readonly FredClient $client) {}

    /**
     * The enabled series of the closed list, keyed by code, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabledSeries(): array
    {
        return array_filter(
            (array) config('macro.series'),
            fn (mixed $series): bool => is_array($series) && ($series['enabled'] ?? false) === true,
        );
    }

    /**
     * @return array{result: string, error: ?string, stored: int, new: int, revised: int, removed: int, latest: ?string}
     */
    public function refresh(string $code, bool $backfill = false): array
    {
        $config = self::enabledSeries()[$code] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException("Unknown or disabled macro series: {$code}");
        }

        $now = now();
        $series = MacroSeries::query()->updateOrCreate(['code' => $code], [
            'source' => MacroSeries::SOURCE_FRED,
            'category' => (string) $config['category'],
            'frequency' => (string) $config['frequency'],
        ]);

        try {
            $start = $this->start($series, (string) $config['frequency'], $backfill);
            $meta = $this->client->series($code);
            if ($config['category'] === 'liquidity' && ! str_starts_with($meta['units'], 'Millions of')) {
                // Net liquidity (Domain Rule 5) depends on the millions scale.
                throw new FredUnavailable('units_changed');
            }
            $observations = $this->client->observations($code, $start);

            return DB::transaction(fn (): array => $this->store($series, $meta, $observations, $now));
        } catch (FredUnavailable $exception) {
            return $this->fail($series, $exception->reason, $now);
        } catch (Throwable $exception) {
            Log::warning('Macro series refresh failed', ['series' => $code, 'exception' => $exception::class]);

            return $this->fail($series, 'internal_error', $now);
        }
    }

    private function start(MacroSeries $series, string $frequency, bool $backfill): Carbon
    {
        $today = now((string) config('ingestion.timezone'))->startOfDay();
        $last = $backfill ? null : $this->lastDate($series);
        if ($last === null) {
            return $today->copy()->subYears(max(1, (int) config('macro.backfill_years')));
        }

        return Carbon::parse($last)->startOfDay()->subDays((int) (config("macro.refetch_days.{$frequency}") ?? 0));
    }

    /** Max stored observation date (`Y-m-d`) or null. */
    private function lastDate(MacroSeries $series): ?string
    {
        $max = MacroObservation::query()->where('macro_series_id', $series->id)->max('date');

        return $max === null ? null : substr((string) $max, 0, 10);
    }

    /**
     * @param  array{title: string, units: string, frequency_short: ?string, last_updated: mixed}  $meta
     * @param  list<array{date: string, value: ?string}>  $observations
     * @return array{result: string, error: ?string, stored: int, new: int, revised: int, removed: int, latest: ?string}
     */
    private function store(MacroSeries $series, array $meta, array $observations, Carbon $now): array
    {
        // Existing rows keyed by `Y-m-d` (SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d").
        $existing = [];
        foreach (DB::table('macro_observations')->where('macro_series_id', $series->id)->get(['id', 'date', 'value']) as $row) {
            $existing[substr((string) $row->date, 0, 10)] = ['id' => (int) $row->id, 'value' => self::normalize($row->value)];
        }

        $inserts = [];
        $revised = 0;
        $removed = [];
        $stored = 0;
        foreach ($observations as $observation) {
            $date = $observation['date'];
            $current = $existing[$date] ?? null;
            if ($observation['value'] === null) {
                if ($current !== null) {
                    $removed[] = $current['id'];
                }

                continue;
            }

            $stored++;
            $value = self::normalize($observation['value']);
            if ($current === null) {
                $inserts[] = [
                    'macro_series_id' => $series->id,
                    'date' => Carbon::parse($date)->startOfDay(),
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ($current['value'] !== $value) {
                MacroObservation::query()->whereKey($current['id'])->update(['value' => $value, 'updated_at' => $now]);
                $revised++;
            }
        }

        foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
            MacroObservation::query()->insert($chunk);
        }
        foreach (array_chunk($removed, self::CHUNK) as $chunk) {
            MacroObservation::query()->whereKey($chunk)->delete();
        }

        $latest = $this->lastDate($series);
        $series->forceFill([
            'title' => Str::limit($meta['title'], 255, ''),
            'units' => Str::limit($meta['units'], 128, ''),
            'source_updated_at' => $meta['last_updated'],
            'last_observation_date' => $latest === null ? null : Carbon::parse($latest)->startOfDay(),
            'last_refreshed_at' => $now,
            'last_attempted_at' => $now,
            'last_error' => null,
        ])->save();

        return [
            'result' => self::RESULT_UPDATED,
            'error' => null,
            'stored' => $stored,
            'new' => count($inserts),
            'revised' => $revised,
            'removed' => count($removed),
            'latest' => $latest,
        ];
    }

    /** @return array{result: string, error: ?string, stored: int, new: int, revised: int, removed: int, latest: ?string} */
    private function fail(MacroSeries $series, string $code, Carbon $now): array
    {
        $series->forceFill(['last_attempted_at' => $now, 'last_error' => $code])->save();

        return [
            'result' => self::RESULT_FAILED,
            'error' => $code,
            'stored' => 0,
            'new' => 0,
            'revised' => 0,
            'removed' => 0,
            'latest' => $this->lastDate($series),
        ];
    }

    /** A published value as a 6-dp string, the stored precision. */
    private static function normalize(mixed $value): string
    {
        return number_format(round((float) $value, 6), 6, '.', '');
    }
}
