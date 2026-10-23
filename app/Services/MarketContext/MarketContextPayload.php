<?php

namespace App\Services\MarketContext;

use App\Models\MarketBreadthSnapshot;
use App\Models\MarketRegimeSnapshot;
use App\Services\Macro\MacroSeriesRefresher;
use App\Services\Macro\MacroSnapshotService;
use App\Services\MarketRegime\MarketRegimeRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Body of `GET /api/market-context` (docs/specs/market-context-ui.md, C1):
 * the latest Market Regime, Sector and Breadth Snapshots and the newest macro
 * readings, each with its own date. Read-only; descriptive data only, no text.
 *
 * `public` is added by the controller after the cache, so a flipped go-live
 * flag never waits for a cache bump.
 */
class MarketContextPayload
{
    /** Macro card => source categories of `config('macro.series')`, in card order. */
    private const SOURCE_CARDS = [
        'rates' => ['rates'],
        'liquidity' => ['liquidity'],
        'central_banks' => ['policy'],
        'leading' => ['leading'],
    ];

    /** Today (`Y-m-d`) in the market timezone. */
    public static function today(): string
    {
        return Carbon::now((string) config('ingestion.timezone', 'America/New_York'))->toDateString();
    }

    /** @return array<string, mixed> */
    public function build(string $asOf): array
    {
        return [
            'as_of' => $asOf,
            'regime' => $this->regime($asOf),
            'sectors' => $this->sectors($asOf),
            'breadth' => $this->breadth($asOf),
            'macro' => $this->macro($asOf),
        ];
    }

    /** @return array<string, mixed>|null */
    private function regime(string $asOf): ?array
    {
        $row = MarketRegimeSnapshot::query()->orderByDesc('date')->first();

        if ($row === null) {
            return null;
        }

        $date = $row->date->toDateString();

        return [
            'date' => $date,
            'stale' => self::stale($date, $asOf),
            'regime' => $row->regime,
            'score' => $row->score,
            'available' => $row->available,
            'rules_version' => $row->rules_version,
            'rules' => [
                'min_available' => MarketRegimeRules::MIN_AVAILABLE,
                'favourable_min_score' => MarketRegimeRules::FAVOURABLE_MIN_SCORE,
                'adverse_max_score' => MarketRegimeRules::ADVERSE_MAX_SCORE,
            ],
            // The stored object unchanged: an absent key was not computed by
            // that rules version. Kept an array: the cache store does not
            // unserialize objects (`cache.serializable_classes` is false).
            'components' => $row->components ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function sectors(string $asOf): ?array
    {
        // The raw stored value: SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d".
        $latest = DB::table('market_sector_snapshots')->max('date');

        if ($latest === null) {
            return null;
        }

        $rows = DB::table('market_sector_snapshots')
            ->join('instruments', 'instruments.id', '=', 'market_sector_snapshots.instrument_id')
            ->where('market_sector_snapshots.date', $latest)
            ->get([
                'instruments.ticker',
                'market_sector_snapshots.sector',
                'market_sector_snapshots.ret_5',
                'market_sector_snapshots.ret_20',
                'market_sector_snapshots.ret_60',
                'market_sector_snapshots.rs_60',
                'market_sector_snapshots.rs_rank',
                'market_sector_snapshots.trend',
                'market_sector_snapshots.breadth_200',
            ])
            ->map(fn (object $row): array => [
                'ticker' => (string) $row->ticker,
                'sector_key' => self::sectorKey($row->sector),
                'ret_5' => self::number($row->ret_5),
                'ret_20' => self::number($row->ret_20),
                'ret_60' => self::number($row->ret_60),
                'rs_60' => self::number($row->rs_60),
                'rs_rank' => $row->rs_rank === null ? null : (int) $row->rs_rank,
                'trend' => $row->trend === null ? null : (string) $row->trend,
                'breadth_200' => self::number($row->breadth_200),
            ])
            ->all();

        usort($rows, function (array $a, array $b): int {
            if ($a['rs_rank'] !== $b['rs_rank']) {
                if ($a['rs_rank'] === null) {
                    return 1;
                }
                if ($b['rs_rank'] === null) {
                    return -1;
                }

                return $a['rs_rank'] <=> $b['rs_rank'];
            }

            return strcmp($a['ticker'], $b['ticker']);
        });

        $date = substr((string) $latest, 0, 10);

        return ['date' => $date, 'stale' => self::stale($date, $asOf), 'rows' => $rows];
    }

    /** @return array<string, mixed>|null */
    private function breadth(string $asOf): ?array
    {
        $universe = DB::table('universes')->where('slug', (string) config('ingestion.universe'))->value('id');
        $row = $universe === null
            ? null
            : MarketBreadthSnapshot::query()->where('universe_id', $universe)->orderByDesc('date')->first();

        if ($row === null) {
            return null;
        }

        $date = $row->date->toDateString();

        return [
            'date' => $date,
            'stale' => self::stale($date, $asOf),
            'members' => $row->members,
            'pct_above_sma50' => self::number($row->pct_above_sma50),
            'pct_above_sma200' => self::number($row->pct_above_sma200),
            'new_highs' => $row->new_highs,
            'new_lows' => $row->new_lows,
            'advancers' => $row->advancers,
            'decliners' => $row->decliners,
            'spy_trend' => $row->spy_trend,
        ];
    }

    /**
     * The newest readings (not point-in-time: each carries its own date), the
     * official calendar of each bank and the attribution of every card.
     *
     * @return array<string, mixed>|null
     */
    private function macro(string $asOf): ?array
    {
        if (! config('macro.enabled')) {
            return null;
        }

        $snapshot = app(MacroSnapshotService::class)->snapshot($asOf);
        unset($snapshot['as_of'], $snapshot['point_in_time'], $snapshot['volatility']);

        foreach (['fed', 'ecb'] as $bank) {
            if (is_array($snapshot['central_banks'][$bank] ?? null)) {
                $snapshot['central_banks'][$bank]['calendar'] = [
                    'source_url' => (string) config("central_banks.{$bank}.source_url"),
                    'checked_at' => (string) config("central_banks.{$bank}.checked_at"),
                ];
            }
        }

        $snapshot['sources'] = self::sources();

        return $snapshot;
    }

    /**
     * De-duplicated `attribution` strings of the enabled series per card, in
     * config order.
     *
     * @return array<string, list<string>>
     */
    public static function sources(): array
    {
        $enabled = MacroSeriesRefresher::enabledSeries();
        $sources = [];

        foreach (self::SOURCE_CARDS as $card => $categories) {
            $sources[$card] = array_values(array_unique(array_map(
                fn (array $series): string => (string) $series['attribution'],
                array_filter($enabled, fn (array $series): bool => in_array($series['category'] ?? null, $categories, true)),
            )));
        }

        return $sources;
    }

    /** `Information Technology` -> `information_technology`. */
    public static function sectorKey(?string $sector): ?string
    {
        return $sector === null ? null : str_replace(' ', '_', strtolower(trim($sector)));
    }

    /** Older than MARKET_STALE_AFTER_DAYS calendar days on `$asOf`. */
    public static function stale(string $date, string $asOf): bool
    {
        return (int) round(Carbon::parse($date)->diffInDays(Carbon::parse($asOf), false)) > MarketRegimeRules::MARKET_STALE_AFTER_DAYS;
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 4);
    }
}
