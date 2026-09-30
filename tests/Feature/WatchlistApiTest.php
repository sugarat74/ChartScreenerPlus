<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract tests for the authenticated, self-owned watchlist endpoints.
 *
 * The invariant under test is ownership: every query is scoped to
 * `$request->user()->watchlist()`, no endpoint accepts a `user_id`, add is
 * idempotent, and remove distinguishes "removed" (`204`) from "was not yours or
 * absent" (`404`).
 */
class WatchlistApiTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(string $ticker): Instrument
    {
        return Instrument::factory()->create([
            'ticker' => $ticker,
            'company' => "{$ticker} Inc.",
        ]);
    }

    public function test_a_guest_is_rejected_from_every_watchlist_route(): void
    {
        $this->instrument('NVDA');

        $this->getJson('/api/watchlist')->assertUnauthorized();
        $this->postJson('/api/watchlist', ['ticker' => 'NVDA'])->assertUnauthorized();
        $this->deleteJson('/api/watchlist/NVDA')->assertUnauthorized();

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_an_empty_watchlist_returns_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/watchlist')
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    public function test_a_user_can_follow_an_instrument(): void
    {
        $user = User::factory()->create();
        $instrument = $this->instrument('NVDA');

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => 'NVDA'])
            ->assertCreated()
            ->assertJsonPath('item.ticker', 'NVDA')
            ->assertJsonPath('item.company', 'NVDA Inc.')
            ->assertJsonStructure([
                'item' => ['ticker', 'company', 'sector', 'exchange', 'active'],
            ]);

        $this->assertDatabaseCount('watchlist_items', 1);
        $this->assertDatabaseHas('watchlist_items', [
            'user_id' => $user->id,
            'instrument_id' => $instrument->id,
        ]);
    }

    public function test_following_is_idempotent(): void
    {
        $user = User::factory()->create();
        $instrument = $this->instrument('NVDA');

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => 'NVDA'])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => 'NVDA'])
            ->assertOk()
            ->assertJsonPath('item.ticker', 'NVDA');

        $this->assertDatabaseCount('watchlist_items', 1);
        $this->assertSame(
            1,
            $user->watchlist()->whereKey($instrument->id)->count(),
        );
    }

    public function test_the_ticker_is_normalized_on_add(): void
    {
        $user = User::factory()->create();
        $instrument = $this->instrument('NVDA');

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => '  nvda '])
            ->assertCreated()
            ->assertJsonPath('item.ticker', 'NVDA');

        $this->assertDatabaseHas('watchlist_items', [
            'user_id' => $user->id,
            'instrument_id' => $instrument->id,
        ]);
    }

    public function test_an_unknown_ticker_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->instrument('NVDA');

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => 'ZZZZ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticker');

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_a_missing_ticker_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/watchlist', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticker');

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_a_non_string_ticker_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['ticker' => ['NVDA']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticker');

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_a_user_can_unfollow_an_instrument(): void
    {
        $user = User::factory()->create();
        $nvda = $this->instrument('NVDA');
        $aapl = $this->instrument('AAPL');

        $user->watchlist()->attach([$nvda->id, $aapl->id]);

        $this->actingAs($user)
            ->deleteJson('/api/watchlist/NVDA')
            ->assertNoContent();

        $this->assertDatabaseMissing('watchlist_items', [
            'user_id' => $user->id,
            'instrument_id' => $nvda->id,
        ]);
        $this->assertDatabaseHas('watchlist_items', [
            'user_id' => $user->id,
            'instrument_id' => $aapl->id,
        ]);
    }

    public function test_unfollowing_an_absent_instrument_is_not_found(): void
    {
        $user = User::factory()->create();
        $this->instrument('MSFT');

        $this->actingAs($user)
            ->deleteJson('/api/watchlist/MSFT')
            ->assertNotFound()
            ->assertJsonPath('message', 'Instrument is not in your watchlist.');

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_unfollowing_an_unknown_ticker_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/watchlist/ZZZZ')
            ->assertNotFound()
            ->assertJsonPath('message', 'Instrument not found.');

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_ownership_is_enforced_across_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $nvda = $this->instrument('NVDA');

        $owner->watchlist()->attach($nvda->id);

        // The other user sees an empty list, not the owner's entry.
        $this->actingAs($other)
            ->getJson('/api/watchlist')
            ->assertOk()
            ->assertExactJson(['items' => []]);

        // Deleting the owner's ticker is a 404 for the other user and changes
        // nothing for the owner.
        $this->actingAs($other)
            ->deleteJson('/api/watchlist/NVDA')
            ->assertNotFound();

        $this->assertDatabaseHas('watchlist_items', [
            'user_id' => $owner->id,
            'instrument_id' => $nvda->id,
        ]);
        $this->assertDatabaseCount('watchlist_items', 1);
    }

    public function test_a_client_supplied_user_id_is_ignored(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $this->instrument('NVDA');

        $this->actingAs($user)
            ->postJson('/api/watchlist', [
                'ticker' => 'NVDA',
                'user_id' => $victim->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('watchlist_items', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('watchlist_items', ['user_id' => $victim->id]);
    }

    public function test_entries_are_ordered_by_ticker(): void
    {
        $user = User::factory()->create();
        $nvda = $this->instrument('NVDA');
        $aapl = $this->instrument('AAPL');
        $msft = $this->instrument('MSFT');

        $user->watchlist()->attach([$nvda->id, $aapl->id, $msft->id]);

        $this->actingAs($user)
            ->getJson('/api/watchlist')
            ->assertOk()
            ->assertJsonPath('items.0.ticker', 'AAPL')
            ->assertJsonPath('items.1.ticker', 'MSFT')
            ->assertJsonPath('items.2.ticker', 'NVDA');
    }

    public function test_deleting_a_user_cascades_their_watchlist_items(): void
    {
        $user = User::factory()->create();
        $instrument = $this->instrument('NVDA');
        $user->watchlist()->attach($instrument->id);

        $user->delete();

        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_deleting_an_instrument_cascades_its_watchlist_items(): void
    {
        $user = User::factory()->create();
        $instrument = $this->instrument('NVDA');
        $user->watchlist()->attach($instrument->id);

        $instrument->delete();

        $this->assertDatabaseCount('watchlist_items', 0);
    }
}
