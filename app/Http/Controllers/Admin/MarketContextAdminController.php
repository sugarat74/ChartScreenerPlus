<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunMarketContextCommand;
use App\Models\MacroSeries;
use App\Models\MarketRegimeSnapshot;
use App\Services\MarketContext\MarketContextRunLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin Market Context tab (docs/specs/market-context-ui.md, C3). Behind
 * `auth:sanctum` + `admin` (401 guest, 403 non-admin). The status reads the
 * run log, the snapshot tables and `macro_series`; a rerun records `queued`
 * and dispatches {@see RunMarketContextCommand} for one of the three commands,
 * always without options.
 */
class MarketContextAdminController extends Controller
{
    /** `GET /api/admin/market-context` */
    public function show(): JsonResponse
    {
        $runs = [];
        foreach (MarketContextRunLog::COMMANDS as $command) {
            $runs[$command] = MarketContextRunLog::get($command);
        }

        return response()->json([
            'public' => (bool) config('market_context.public'),
            'macro_enabled' => (bool) config('macro.enabled'),
            'runs' => $runs,
            'snapshots' => [
                'sectors' => $this->sectorCoverage(),
                'breadth' => $this->breadthCoverage(),
                'regime' => $this->regimeCoverage(),
            ],
            'series' => $this->series(),
        ]);
    }

    /** `POST /api/admin/market-context/runs` */
    public function rerun(Request $request): JsonResponse
    {
        $command = $request->input('command');

        if (! is_string($command) || ! in_array($command, MarketContextRunLog::COMMANDS, true)) {
            throw ValidationException::withMessages(['command' => [__('market_context.command_invalid')]]);
        }

        if ($command === 'macro:refresh' && ! config('macro.enabled')) {
            throw ValidationException::withMessages(['command' => [__('market_context.macro_disabled')]]);
        }

        if (MarketContextRunLog::isActive($command)) {
            return response()->json(['message' => __('market_context.run_active')], 409);
        }

        $run = MarketContextRunLog::queued($command);
        RunMarketContextCommand::dispatch($command);

        return response()->json(['command' => $command, 'run' => MarketContextRunLog::get($command) ?? $run], 202);
    }

    /** @return array{first_date: string, latest_date: string, latest_rows: int}|null */
    private function sectorCoverage(): ?array
    {
        $first = DB::table('market_sector_snapshots')->min('date');
        $latest = DB::table('market_sector_snapshots')->max('date');

        if ($first === null || $latest === null) {
            return null;
        }

        return [
            'first_date' => self::day($first),
            'latest_date' => self::day($latest),
            'latest_rows' => DB::table('market_sector_snapshots')->where('date', $latest)->count(),
        ];
    }

    /** @return array{first_date: string, latest_date: string}|null */
    private function breadthCoverage(): ?array
    {
        $universe = DB::table('universes')->where('slug', (string) config('ingestion.universe'))->value('id');

        if ($universe === null) {
            return null;
        }

        $query = DB::table('market_breadth_snapshots')->where('universe_id', $universe);
        $first = (clone $query)->min('date');
        $latest = (clone $query)->max('date');

        return $first === null || $latest === null ? null : ['first_date' => self::day($first), 'latest_date' => self::day($latest)];
    }

    /** @return array<string, mixed>|null */
    private function regimeCoverage(): ?array
    {
        $latest = MarketRegimeSnapshot::query()->orderByDesc('date')->first();

        if ($latest === null) {
            return null;
        }

        $counts = DB::table('market_regime_snapshots')
            ->select('regime', DB::raw('count(*) as total'))
            ->groupBy('regime')
            ->pluck('total', 'regime');

        $distribution = [];
        foreach ([
            MarketRegimeSnapshot::REGIME_FAVOURABLE,
            MarketRegimeSnapshot::REGIME_NEUTRAL,
            MarketRegimeSnapshot::REGIME_ADVERSE,
            MarketRegimeSnapshot::REGIME_INSUFFICIENT,
        ] as $regime) {
            $distribution[$regime] = (int) ($counts[$regime] ?? 0);
        }

        return [
            'first_date' => self::day(DB::table('market_regime_snapshots')->min('date')),
            'latest_date' => $latest->date->toDateString(),
            'count' => DB::table('market_regime_snapshots')->count(),
            'rules_version' => $latest->rules_version,
            'latest' => ['regime' => $latest->regime, 'score' => $latest->score, 'available' => $latest->available],
            'distribution' => $distribution,
        ];
    }

    /**
     * Every configured series in config order, joined with its refresh state.
     *
     * @return list<array<string, mixed>>
     */
    private function series(): array
    {
        $rows = MacroSeries::query()->get()->keyBy('code');
        $today = Carbon::parse(Carbon::now((string) config('ingestion.timezone', 'America/New_York'))->toDateString());
        $series = [];

        foreach ((array) config('macro.series') as $code => $config) {
            /** @var MacroSeries|null $row */
            $row = $rows->get($code);
            $frequency = (string) ($config['frequency'] ?? 'daily');
            $last = $row?->last_observation_date;
            $threshold = (int) config("macro.stale_after_days.{$frequency}", 7);

            $series[] = [
                'code' => (string) $code,
                'category' => (string) ($config['category'] ?? ''),
                'frequency' => $frequency,
                'license' => (string) ($config['license'] ?? ''),
                'enabled' => ($config['enabled'] ?? false) === true,
                'last_observation_date' => $last?->toDateString(),
                'last_refreshed_at' => $row?->last_refreshed_at?->utc()->toIso8601ZuluString(),
                'last_attempted_at' => $row?->last_attempted_at?->utc()->toIso8601ZuluString(),
                'last_error' => $row?->last_error,
                'stale' => $last !== null
                    && (int) round(Carbon::parse($last->toDateString())->diffInDays($today, false)) > $threshold,
            ];
        }

        return $series;
    }

    /** A stored date as `Y-m-d` (SQLite keeps `Y-m-d H:i:s`, PostgreSQL `Y-m-d`). */
    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
