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

];
