<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Screener universes (the visibility flag and kill-switch)
    |--------------------------------------------------------------------------
    |
    | Comma-separated slugs of the Screener universes that everyone, Visitors
    | included, can see and query. A seeded Screener universe that is not
    | listed is visible to Admins only (preview), on every surface; ingestion
    | still runs for it. Removing a slug and running `php artisan config:cache`
    | hides that universe at once from every non-Admin surface (selector, API
    | 404, Saved Screeners; alerts on it are skipped) without deleting data.
    | docs/specs/multi-market-universes.md, D9.
    |
    */

    'public' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SCREENER_UNIVERSES_PUBLIC', 'sp500')),
    ), fn (string $slug): bool => $slug !== '')),

];
