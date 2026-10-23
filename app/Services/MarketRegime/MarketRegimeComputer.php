<?php

namespace App\Services\MarketRegime;

use App\Models\MarketRegimeSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Models\Universe;
use App\Services\Macro\MacroSnapshotService;
use App\Services\MarketContext\MarketContextComputer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Computes the daily Market Regime (docs/specs/market-regime.md, Domain
 * Rules 3-7) from the stored Breadth and Sector Snapshots and the Macro
 * Series, without look-ahead:
 *
 * - the regime of `d` reads only snapshot rows dated on or before `d`, and
 *   macro readings in point-in-time mode (only observations already published
 *   on `d`), for the live run as well as the backfill;
 * - each component reads its latest reading on or before `d`; a stale one is
 *   kept for display but never counted, and there is no fallback to an older
 *   reading;
 * - snapshot rows are loaded once per run and their dates normalised in PHP
 *   (SQLite stores `Y-m-d 00:00:00`, PostgreSQL `Y-m-d`);
 * - a fresh `MacroSnapshotService` is resolved inside every `compute()`,
 *   because it caches the observations per instance.
 *
 * Descriptive only; the backend stores no text.
 */
class MarketRegimeComputer
{
    public function __construct(private readonly MarketContextComputer $context) {}

    /**
     * The main (stock) Universe, `config('ingestion.universe')`.
     */
    public function mainUniverse(): ?Universe
    {
        return $this->context->mainUniverse();
    }

    /**
     * Date (`Y-m-d`) of the main Universe's first Breadth Snapshot, or null
     * when there is none yet.
     */
    public function firstSnapshotDate(): ?string
    {
        $value = DB::table('market_breadth_snapshots')
            ->where('universe_id', $this->requireMainUniverse()->id)
            ->orderBy('date')
            ->value('date');

        return $value === null ? null : $this->day($value);
    }

    /**
     * The sessions to compute (`Y-m-d`, ascending), never before the first
     * Breadth Snapshot: `$date` alone gives `[$date]` when it is such a
     * session, `$from` every such session on or after it, and neither the
     * latest 5 sessions, or every session from the first snapshot when the
     * regime table is still empty (the self-seed).
     *
     * @return list<string>
     */
    public function sessionDates(?string $from = null, ?string $date = null): array
    {
        $first = $this->firstSnapshotDate();

        if ($first === null) {
            return [];
        }

        if ($date === null && $from === null && ! MarketRegimeSnapshot::query()->exists()) {
            $from = $first;
        }

        $sessions = $this->context->sessionDates($from, $date);

        return array_values(array_filter($sessions, fn (string $session): bool => $session >= $first));
    }

    /**
     * Compute and upsert the regime of `$dates` (`Y-m-d`). Idempotent.
     *
     * @param  list<string>  $dates
     * @return array{rows: int, latest: array{date: string, regime: string, score: int|null, available: int}|null}
     */
    public function compute(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($dates === []) {
            return ['rows' => 0, 'latest' => null];
        }

        $main = $this->requireMainUniverse();
        $last = $dates[array_key_last($dates)];
        [$breadth, $trend] = $this->breadthReadings($main, $last);
        $sectors = $this->sectorReadings($last);

        // Fresh per run: the service caches the stored observations per instance.
        $macro = config('macro.enabled') ? app(MacroSnapshotService::class) : null;

        $now = Carbon::now();
        $rows = [];
        $latest = null;

        foreach ($dates as $date) {
            $snapshot = $macro?->snapshot($date, pointInTime: true);

            $components = [
                'breadth' => $this->marketComponent('breadth', $breadth, $date),
                'index_trend' => $this->marketComponent('index_trend', $trend, $date),
                'sector_participation' => $this->marketComponent('sector_participation', $sectors, $date),
                'yield_curve' => $this->macroComponent('yield_curve', $snapshot, $snapshot['curve']['spread_10y3m'] ?? null),
                'financial_conditions' => $this->macroComponent('financial_conditions', $snapshot, $snapshot['leading']['nfci'] ?? null),
                'liquidity' => $this->macroComponent('liquidity', $snapshot, $snapshot['liquidity']['change_13w_pct'] ?? null),
                'volatility' => $this->macroComponent('volatility', $snapshot, $snapshot['volatility']['vix'] ?? null),
            ];

            $ok = array_filter($components, fn (array $component): bool => $component['status'] === MarketRegimeSnapshot::STATUS_OK);
            $available = count($ok);
            $sum = array_sum(array_column($ok, 'score'));
            $regime = MarketRegimeRules::label($sum, $available);
            $score = $regime === MarketRegimeSnapshot::REGIME_INSUFFICIENT ? null : $sum;

            $rows[] = [
                'date' => Carbon::parse($date)->startOfDay(),
                'regime' => $regime,
                'score' => $score,
                'available' => $available,
                // upsert() skips the model casts.
                'components' => json_encode($components, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'rules_version' => MarketRegimeRules::RULES_VERSION,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $latest = ['date' => $date, 'regime' => $regime, 'score' => $score, 'available' => $available];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                MarketRegimeSnapshot::query()->upsert(
                    $chunk,
                    ['date'],
                    ['regime', 'score', 'available', 'components', 'rules_version', 'updated_at'],
                );
            }
        });

        return ['rows' => count($rows), 'latest' => $latest];
    }

    /**
     * The main Universe's Breadth readings up to `$last`: `[pct_above_sma200
     * by date, spy_trend by date]`, ascending, null values left out.
     *
     * @return array{0: array<string, float>, 1: array<string, string>}
     */
    private function breadthReadings(Universe $main, string $last): array
    {
        $breadth = [];
        $trend = [];

        $rows = DB::table('market_breadth_snapshots')
            ->where('universe_id', $main->id)
            ->orderBy('date')
            ->get(['date', 'pct_above_sma200', 'spy_trend']);

        foreach ($rows as $row) {
            $date = $this->day($row->date);

            if ($date > $last) {
                continue;
            }

            if ($row->pct_above_sma200 !== null) {
                $breadth[$date] = round((float) $row->pct_above_sma200, 4) + 0.0;
            }

            if ($row->spy_trend !== null) {
                $trend[$date] = (string) $row->spy_trend;
            }
        }

        return [$breadth, $trend];
    }

    /**
     * Count of sector ETFs in `uptrend` per date up to `$last`, only on dates
     * where all SECTOR_COUNT sectors have a known trend (a partial count is
     * never scored).
     *
     * @return array<string, int>
     */
    private function sectorReadings(string $last): array
    {
        $known = [];
        $up = [];

        $rows = DB::table('market_sector_snapshots')
            ->whereNotNull('trend')
            ->orderBy('date')
            ->get(['date', 'trend']);

        foreach ($rows as $row) {
            $date = $this->day($row->date);

            if ($date > $last) {
                continue;
            }

            $known[$date] = ($known[$date] ?? 0) + 1;
            $up[$date] = ($up[$date] ?? 0) + ($row->trend === MarketSectorSnapshot::TREND_UP ? 1 : 0);
        }

        $complete = [];
        foreach ($known as $date => $count) {
            if ($count >= MarketRegimeRules::SECTOR_COUNT) {
                $complete[$date] = $up[$date];
            }
        }

        return $complete;
    }

    /**
     * A market component from its readings (`Y-m-d => value`, ascending):
     * the latest reading on or before `$date`, stale when older than
     * MARKET_STALE_AFTER_DAYS.
     *
     * @param  array<string, int|float|string>  $readings
     * @return array{status: string, score: int|null, value: int|float|string|null, as_of: string|null, stale: bool}
     */
    private function marketComponent(string $key, array $readings, string $date): array
    {
        $asOf = null;

        foreach (array_reverse(array_keys($readings)) as $candidate) {
            if ($candidate <= $date) {
                $asOf = $candidate;
                break;
            }
        }

        if ($asOf === null) {
            return $this->component(MarketRegimeSnapshot::STATUS_MISSING);
        }

        $age = (int) round(Carbon::parse($asOf)->diffInDays(Carbon::parse($date)));
        $stale = $age > MarketRegimeRules::MARKET_STALE_AFTER_DAYS;

        return $this->scored($key, $readings[$asOf], $asOf, $stale);
    }

    /**
     * A macro component from its point-in-time reading `{value, as_of,
     * stale}`; `disabled` when macro is off (`$snapshot` null) or the reading
     * is null (series disabled).
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  array{value: float|null, as_of: string|null, stale: bool}|null  $reading
     * @return array{status: string, score: int|null, value: int|float|string|null, as_of: string|null, stale: bool}
     */
    private function macroComponent(string $key, ?array $snapshot, ?array $reading): array
    {
        if ($snapshot === null || $reading === null) {
            return $this->component(MarketRegimeSnapshot::STATUS_DISABLED);
        }

        if ($reading['value'] === null || $reading['as_of'] === null) {
            return $this->component(MarketRegimeSnapshot::STATUS_MISSING);
        }

        return $this->scored($key, round((float) $reading['value'], 4) + 0.0, (string) $reading['as_of'], (bool) $reading['stale']);
    }

    /**
     * An `ok` component with its score, or a `stale` one keeping its value
     * and date without a score.
     *
     * @return array{status: string, score: int|null, value: int|float|string, as_of: string, stale: bool}
     */
    private function scored(string $key, int|float|string $value, string $asOf, bool $stale): array
    {
        return [
            'status' => $stale ? MarketRegimeSnapshot::STATUS_STALE : MarketRegimeSnapshot::STATUS_OK,
            'score' => $stale ? null : MarketRegimeRules::score($key, $value),
            'value' => $value,
            'as_of' => $asOf,
            'stale' => $stale,
        ];
    }

    /**
     * A `missing` or `disabled` component: no score, value or date.
     *
     * @return array{status: string, score: null, value: null, as_of: null, stale: false}
     */
    private function component(string $status): array
    {
        return ['status' => $status, 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false];
    }

    private function requireMainUniverse(): Universe
    {
        $universe = $this->mainUniverse();

        if ($universe === null) {
            throw new RuntimeException('No universe found for ['.config('ingestion.universe').'].');
        }

        return $universe;
    }

    /**
     * A stored date as `Y-m-d` (SQLite returns `Y-m-d H:i:s`, PostgreSQL `Y-m-d`).
     */
    private function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
