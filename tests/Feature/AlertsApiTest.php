<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * alerts-engine API: ownership (404), limits and duplicates (422, localized),
 * guests (401), baseline reset, notifications listing and mark-as-read.
 */
class AlertsApiTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = [
        'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null,
        'price_above_sma200' => false, 'ma_cross' => null, 'sort' => 'rvol_desc',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function screenerOf(User $user, string $name = 'Mi screener'): int
    {
        return $user->savedScreeners()->create(['name' => $name, 'filters' => self::FILTERS])->id;
    }

    public function test_guests_are_rejected_from_every_route(): void
    {
        $this->getJson('/api/alerts')->assertUnauthorized();
        $this->postJson('/api/alerts', [])->assertUnauthorized();
        $this->patchJson('/api/alerts/1', [])->assertUnauthorized();
        $this->deleteJson('/api/alerts/1')->assertUnauthorized();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/read', ['all' => true])->assertUnauthorized();
    }

    public function test_a_user_creates_both_kinds_and_lists_only_their_own(): void
    {
        $user = User::factory()->create();
        $screenerId = $this->screenerOf($user);
        Alert::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/alerts', ['kind' => 'screener_new_candidates', 'saved_screener_id' => $screenerId])
            ->assertCreated()
            ->assertJsonPath('alert.kind', 'screener_new_candidates')
            ->assertJsonPath('alert.saved_screener.name', 'Mi screener')
            ->assertJsonPath('alert.active', true)
            ->assertJsonPath('alert.last_evaluated_as_of', null);

        $this->actingAs($user)
            ->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['rsi_oversold', 'golden_cross', 'golden_cross']])
            ->assertCreated()
            ->assertJsonPath('alert.signal_types', ['golden_cross', 'rsi_oversold']);

        $this->actingAs($user)->getJson('/api/alerts')->assertOk()->assertJsonCount(2, 'alerts');
    }

    public function test_validation_ownership_duplicates_and_limit(): void
    {
        $user = User::factory()->create();
        $foreignScreener = $this->screenerOf(User::factory()->create(), 'Ajeno');

        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'price_level'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'screener_new_candidates'])->assertUnprocessable()->assertJsonValidationErrors('saved_screener_id');
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'screener_new_candidates', 'saved_screener_id' => $foreignScreener])
            ->assertUnprocessable()->assertJsonValidationErrors('saved_screener_id');
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => []])->assertUnprocessable()->assertJsonValidationErrors('signal_types');
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['triangle']])->assertUnprocessable()->assertJsonValidationErrors('signal_types.0');

        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['golden_cross']])->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['rsi_oversold']], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'You already have this alert.');

        config(['alerts.max_per_user' => 2]);
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'screener_new_candidates', 'saved_screener_id' => $this->screenerOf($user, 'Uno')])->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/alerts', ['kind' => 'screener_new_candidates', 'saved_screener_id' => $this->screenerOf($user, 'Dos')], ['Accept-Language' => 'es'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'Puedes tener como máximo 2 alertas.');
    }

    public function test_update_and_delete_are_scoped_and_reactivation_resets_the_baseline(): void
    {
        $user = User::factory()->create();
        $alert = Alert::factory()->for($user)->create();
        $alert->forceFill(['last_state' => ['signals' => []], 'last_evaluated_as_of' => '2026-10-01'])->save();
        $foreign = Alert::factory()->create();

        $this->actingAs($user)->patchJson("/api/alerts/{$foreign->id}", ['active' => false])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/alerts/{$foreign->id}")->assertNotFound();
        $this->actingAs($user)->patchJson('/api/alerts/abc', ['active' => false])->assertNotFound();
        $this->assertTrue($foreign->fresh()->active);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['active' => false])->assertOk()->assertJsonPath('alert.active', false);
        $this->assertNotNull($alert->fresh()->last_state);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['active' => true])->assertOk();
        $this->assertNull($alert->fresh()->last_state);
        $this->assertNull($alert->fresh()->last_evaluated_as_of);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['macd_bullish_cross']])
            ->assertOk()->assertJsonPath('alert.signal_types', ['macd_bullish_cross']);

        $this->actingAs($user)->deleteJson("/api/alerts/{$alert->id}")->assertNoContent();
        $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
        $this->assertDatabaseHas('alerts', ['id' => $foreign->id]);
    }

    public function test_screener_alerts_cannot_take_signal_types(): void
    {
        $user = User::factory()->create();
        $alert = $user->alerts()->create(['kind' => 'screener_new_candidates', 'saved_screener_id' => $this->screenerOf($user)]);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['golden_cross']])
            ->assertUnprocessable()->assertJsonValidationErrors('signal_types');
    }

    public function test_notifications_are_listed_newest_first_and_marked_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (['2026-10-01', '2026-10-02'] as $asOf) {
            $user->notify(new AlertTriggered(1, 'watchlist_signal', $asOf, null, null, [['ticker' => 'NVDA', 'name' => 'NVIDIA', 'reason' => 'golden_cross']], 0));
            $this->travel(1)->minutes();
        }
        $other->notify(new AlertTriggered(2, 'watchlist_signal', '2026-10-02', null, null, [], 0));

        $response = $this->actingAs($user)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.as_of', '2026-10-02')
            ->assertJsonPath('data.0.items.0.reason', 'golden_cross')
            ->assertJsonPath('data.0.read_at', null);

        $first = $response->json('data.0.id');
        $foreignId = $other->notifications()->sole()->id;

        $this->actingAs($user)->postJson('/api/notifications/read', ['ids' => [$first, $foreignId]])->assertNoContent();
        $this->actingAs($user)->getJson('/api/notifications')->assertJsonPath('unread_count', 1);
        $this->assertNull($other->notifications()->sole()->read_at);

        $this->actingAs($user)->postJson('/api/notifications/read', ['all' => true])->assertNoContent();
        $this->actingAs($user)->getJson('/api/notifications')->assertJsonPath('unread_count', 0);

        $this->actingAs($user)->postJson('/api/notifications/read', ['ids' => ['not-a-uuid']])->assertUnprocessable();
    }
}
