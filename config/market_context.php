<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market Context (docs/specs/market-context-sectors.md)
    |--------------------------------------------------------------------------
    |
    | `universe` is the slug of the separate Universe holding SPY and the 11
    | SPDR sector ETFs (`kind = etf`), seeded by MarketContextUniverseSeeder.
    | It is not a Screener universe. `benchmark` is the ticker every sector's
    | relative strength is measured against and whose trend is the Breadth
    | row's `spy_trend`. The main (stock) Universe stays
    | `config('ingestion.universe')`.
    |
    */

    'universe' => env('MARKET_CONTEXT_UNIVERSE', 'market-context'),

    'benchmark' => env('MARKET_CONTEXT_BENCHMARK', 'SPY'),

    /*
    |--------------------------------------------------------------------------
    | Market Context page and API (docs/specs/market-context-ui.md)
    |--------------------------------------------------------------------------
    |
    | `public` is the go-live switch of every Market Context surface
    | (`/mercado`, its APIs, the chart strip and the Screener sector filters).
    | Even when it is on, only Registered Users get in: the single
    | `view-market-context` ability (AppServiceProvider,
    | docs/specs/market-context-patterns.md C1). While it is false only
    | Admins can open them, as a preview. Owner decision 2026-10-23 (option
    | b): it is turned on only once the legal notice, which carries the FRED
    | terms-of-use clause, is published (spec, "Legal Gate").
    |
    | `cache_ttl_seconds` bounds the cached payload and history; the three
    | Market Context commands also renew the cache version when they finish.
    | A run record still `queued`/`running` after `run_stale_after_minutes`
    | is reported as `interrupted` and no longer blocks an Admin rerun.
    |
    */

    'public' => (bool) env('MARKET_CONTEXT_PUBLIC', false),

    'cache_ttl_seconds' => 86400,

    'run_stale_after_minutes' => 30,

];
