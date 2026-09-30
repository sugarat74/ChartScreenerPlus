<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-cutting regression coverage for the public browse and private-resource
 * boundaries. Resource-specific validation remains in its own feature suites.
 */
class AccessControlGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = [
        'signal' => [],
        'rsi_min' => null,
        'rsi_max' => null,
        'min_rvol' => null,
        'price_above_sma200' => false,
        'ma_cross' => null,
        'sort' => 'rvol_desc',
    ];

    public function test_a_guest_can_browse_the_public_screener_and_instrument_endpoints(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        $universe->instruments()->attach($instrument->id);

        $this->getJson('/api/screener')
            ->assertOk()
            ->assertJsonPath('universe.slug', 'sp500');

        $this->getJson('/api/instruments/NVDA')
            ->assertOk()
            ->assertJsonPath('instrument.ticker', 'NVDA');
    }

    public function test_a_guest_is_rejected_from_every_owned_resource_route_without_writing(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        $this->getJson('/api/watchlist')->assertUnauthorized();
        $this->postJson('/api/watchlist', ['ticker' => 'NVDA'])->assertUnauthorized();
        $this->deleteJson('/api/watchlist/NVDA')->assertUnauthorized();

        $this->getJson('/api/screeners')->assertUnauthorized();
        $this->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => self::FILTERS])
            ->assertUnauthorized();
        $this->deleteJson('/api/screeners/1')->assertUnauthorized();

        $this->assertDatabaseCount('watchlist_items', 0);
        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_owned_resources_are_listed_and_deleted_only_by_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        $owner->watchlist()->attach($instrument->id);
        $screener = SavedScreener::factory()->for($owner)->create(['name' => 'Owner screener']);

        $this->actingAs($other)->getJson('/api/watchlist')
            ->assertOk()
            ->assertExactJson(['items' => []]);
        $this->actingAs($other)->getJson('/api/screeners')
            ->assertOk()
            ->assertExactJson(['screeners' => []]);

        $this->actingAs($other)->deleteJson('/api/watchlist/NVDA')->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/screeners/{$screener->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Screener not found.');

        $this->assertDatabaseHas('watchlist_items', [
            'user_id' => $owner->id,
            'instrument_id' => $instrument->id,
        ]);
        $this->assertDatabaseHas('saved_screeners', [
            'id' => $screener->id,
            'user_id' => $owner->id,
        ]);
    }
}
