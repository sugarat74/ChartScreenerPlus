<?php

namespace Tests\Feature;

use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * market-hours-status: `GET /api/markets/status` is public, follows the
 * visibility of the Screener universes and never reads market data.
 */
class MarketStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function universe(string $slug, string $market): Universe
    {
        return Universe::factory()->create(['slug' => $slug, 'name' => $slug, 'market' => $market]);
    }

    public function test_it_lists_only_the_markets_of_public_universes_in_a_stable_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 15:00:00', 'UTC'));
        config(['universes.public' => ['sp500', 'dax', 'nasdaq100']]);
        $this->universe('dax', 'XETR');
        $this->universe('sp500', 'US');
        $this->universe('nasdaq100', 'US');
        $this->universe('ibex35', 'XMAD');
        $this->universe('cac40', 'XPAR');

        $response = $this->getJson('/api/markets/status')->assertOk();

        $this->assertSame(['US', 'XETR'], array_column($response->json('markets'), 'code'));
        $this->assertSame('2026-07-06T15:00:00Z', $response->json('generated_at'));
        $us = $response->json('markets.0');
        $this->assertTrue($us['is_open']);
        $this->assertSame('America/New_York', $us['timezone']);
        $this->assertSame('2026-07-06T13:30:00Z', $us['opens_at']);
        $this->assertSame('2026-07-06T20:00:00Z', $us['closes_at']);
        $this->assertSame(['open' => '09:30', 'close' => '16:00'], $us['regular_hours']);
    }

    public function test_paris_appears_in_the_market_order_once_its_universe_is_public(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 15:00:00', 'UTC'));
        config(['universes.public' => ['sp500', 'ibex35', 'dax', 'cac40']]);
        $this->universe('cac40', 'XPAR');
        $this->universe('dax', 'XETR');
        $this->universe('ibex35', 'XMAD');
        $this->universe('sp500', 'US');

        $response = $this->getJson('/api/markets/status')->assertOk();

        $this->assertSame(['US', 'XMAD', 'XETR', 'XPAR'], array_column($response->json('markets'), 'code'));
        $paris = $response->json('markets.3');
        $this->assertSame('Europe/Paris', $paris['timezone']);
        $this->assertTrue($paris['is_open']);
        $this->assertSame('2026-07-06T07:00:00Z', $paris['opens_at']);
        $this->assertSame('2026-07-06T15:30:00Z', $paris['closes_at']);
        $this->assertSame(['open' => '09:00', 'close' => '17:30'], $paris['regular_hours']);
    }

    public function test_the_kill_switch_hides_a_market_and_an_admin_previews_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 15:00:00', 'UTC'));
        config(['universes.public' => ['sp500']]);
        $this->universe('sp500', 'US');
        $this->universe('ibex35', 'XMAD');

        $this->getJson('/api/markets/status')->assertOk()->assertJsonCount(1, 'markets');

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $this->getJson('/api/markets/status')->assertOk()->assertJsonCount(2, 'markets');
    }

    public function test_no_visible_universe_means_an_empty_list(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 15:00:00', 'UTC'));
        config(['universes.public' => []]);
        $this->universe('sp500', 'US');

        $this->getJson('/api/markets/status')->assertOk()->assertExactJson([
            'generated_at' => '2026-07-06T15:00:00Z',
            'markets' => [],
        ]);
    }
}
