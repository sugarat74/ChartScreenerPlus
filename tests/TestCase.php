<?php

namespace Tests;

use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Rebuild the precomputed Screener rows after a test seeded market data
     * directly (production rebuilds them in the pipeline, the Admin job and on
     * deploy). Returns `$this`, so it chains: `->refreshScreenerRows()->getJson(...)`.
     */
    protected function refreshScreenerRows(): static
    {
        app(ScreenerRowBuilder::class)->refresh();

        return $this;
    }
}
