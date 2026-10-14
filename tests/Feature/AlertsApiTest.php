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

    public function test_changing_only_the_signal_types_resets_the_baseline(): void
    {
        $user = User::factory()->create();
        $alert = Alert::factory()->for($user)->create(['signal_types' => ['golden_cross']]);
        $alert->forceFill(['last_state' => ['signals' => ['NVDA' => ['golden_cross']]], 'last_evaluated_as_of' => '2026-10-01'])->save();

        // Same set in another order: canonical, so nothing resets.
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['golden_cross']])->assertOk();
        $this->assertNotNull($alert->fresh()->last_state);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['golden_cross', 'rsi_oversold']])->assertOk();
        $this->assertNull($alert->fresh()->last_state);
        $this->assertNull($alert->fresh()->last_evaluated_as_of);
    }

    public function test_deleting_an_account_removes_its_alerts_and_notifications(): void
    {
        $user = User::factory()->create();
        Alert::factory()->for($user)->create();
        $user->notify(new AlertTriggered(1, 'watchlist_signal', '2026-10-01', null, null, [], 0));
        $other = User::factory()->create();
        $other->notify(new AlertTriggered(2, 'watchlist_signal', '2026-10-01', null, null, [], 0));

        $user->delete();

        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_screener_alerts_cannot_take_signal_types(): void
    {
        $user = User::factory()->create();
        $alert = $user->alerts()->create(['kind' => 'screener_new_candidates', 'saved_screener_id' => $this->screenerOf($user)]);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['golden_cross']])
            ->assertUnprocessable()->assertJsonValidationErrors('signal_types');
    }

    public function test_a_user_creates_a_pattern_alert_with_canonical_lists_and_default_events(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/alerts', ['kind' => 'watchlist_pattern', 'pattern_types' => ['bull_flag', 'double_top', 'bull_flag']])
            ->assertCreated()
            ->assertJsonPath('alert.kind', 'watchlist_pattern')
            ->assertJsonPath('alert.pattern_types', ['double_top', 'bull_flag'])
            ->assertJsonPath('alert.pattern_events', ['confirmed'])
            ->assertJsonPath('alert.signal_types', null)
            ->assertJsonPath('alert.saved_screener', null)
            ->assertJsonPath('alert.active', true);

        $other = User::factory()->create();
        $this->actingAs($other)
            ->postJson('/api/alerts', ['kind' => 'watchlist_pattern', 'pattern_types' => ['double_bottom'], 'pattern_events' => ['confirmed', 'appears']])
            ->assertCreated()
            ->assertJsonPath('alert.pattern_events', ['appears', 'confirmed']);

        // A user may hold one signal alert and one pattern alert at once.
        $this->actingAs($user)->postJson('/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['golden_cross']])
            ->assertCreated()
            ->assertJsonPath('alert.pattern_types', null)
            ->assertJsonPath('alert.pattern_events', null);

        $this->actingAs($user)->getJson('/api/alerts')->assertOk()->assertJsonCount(2, 'alerts');
    }

    public function test_pattern_alert_validation_duplicates_and_limit(): void
    {
        $user = User::factory()->create();
        $screenerId = $this->screenerOf($user);
        $post = fn (array $data, string $locale = 'es') => $this->actingAs($user)->postJson('/api/alerts', $data, ['Accept-Language' => $locale]);

        $this->assertStringContainsString('tipos de patrón', $post(['kind' => 'watchlist_pattern'])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern_types')->json('errors.pattern_types.0'));
        $this->assertStringContainsString('pattern types', $post(['kind' => 'watchlist_pattern'], 'en')
            ->assertUnprocessable()->json('errors.pattern_types.0'));
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => []])->assertUnprocessable()->assertJsonValidationErrors('pattern_types');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['triangle']])->assertUnprocessable()->assertJsonValidationErrors('pattern_types.0');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['double_top'], 'pattern_events' => []])->assertUnprocessable()->assertJsonValidationErrors('pattern_events');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['double_top'], 'pattern_events' => ['disappears']])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern_events.0');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['double_top'], 'signal_types' => ['golden_cross']])
            ->assertUnprocessable()->assertJsonValidationErrors('signal_types');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['double_top'], 'saved_screener_id' => $screenerId])
            ->assertUnprocessable()->assertJsonValidationErrors('saved_screener_id');
        $post(['kind' => 'watchlist_signal', 'signal_types' => ['golden_cross'], 'pattern_types' => ['double_top']])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern_types');
        $post(['kind' => 'screener_new_candidates', 'saved_screener_id' => $screenerId, 'pattern_events' => ['appears']])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern_events');
        $this->assertSame(0, $user->alerts()->count());

        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['double_top']])->assertCreated();
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['bull_flag']], 'es')
            ->assertUnprocessable()->assertJsonPath('errors.kind.0', 'Ya tienes esta alerta.');
        $post(['kind' => 'watchlist_pattern', 'pattern_types' => ['bull_flag']], 'en')
            ->assertUnprocessable()->assertJsonPath('errors.kind.0', 'You already have this alert.');

        config(['alerts.max_per_user' => 1]);
        $post(['kind' => 'watchlist_signal', 'signal_types' => ['golden_cross']], 'en')
            ->assertUnprocessable()->assertJsonPath('errors.kind.0', 'You can have at most 1 alerts.');
    }

    public function test_pattern_alert_updates_reset_the_baseline_only_on_a_different_set(): void
    {
        $user = User::factory()->create();
        $alert = Alert::factory()->for($user)->watchlistPattern(['double_top', 'bull_flag'], ['confirmed'])->create();
        $baseline = ['patterns' => ['NVDA' => ['double_top' => ['start' => '2026-08-03', 'status' => 'forming']]]];
        $seed = fn () => $alert->refresh()->forceFill(['last_state' => $baseline, 'last_evaluated_as_of' => '2026-10-01'])->save();
        $seed();

        // Same sets in another order: canonical, so nothing resets.
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_types' => ['bull_flag', 'double_top'], 'pattern_events' => ['confirmed']])
            ->assertOk()->assertJsonPath('alert.pattern_types', ['double_top', 'bull_flag']);
        $this->assertSame($baseline, $alert->fresh()->last_state);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_types' => ['double_bottom']])
            ->assertOk()->assertJsonPath('alert.pattern_types', ['double_bottom']);
        $this->assertNull($alert->fresh()->last_state);
        $this->assertNull($alert->fresh()->last_evaluated_as_of);

        $seed();
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_events' => ['appears', 'confirmed']])
            ->assertOk()->assertJsonPath('alert.pattern_events', ['appears', 'confirmed']);
        $this->assertNull($alert->fresh()->last_state);

        $seed();
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['active' => false])->assertOk()->assertJsonPath('alert.active', false);
        $this->assertSame($baseline, $alert->fresh()->last_state);
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['active' => true])->assertOk();
        $this->assertNull($alert->fresh()->last_state);

        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_types' => ['triangle']])->assertUnprocessable()->assertJsonValidationErrors('pattern_types.0');
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_types' => []])->assertUnprocessable()->assertJsonValidationErrors('pattern_types');
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['pattern_events' => ['disappears']])->assertUnprocessable()->assertJsonValidationErrors('pattern_events.0');
        $this->actingAs($user)->patchJson("/api/alerts/{$alert->id}", ['signal_types' => ['golden_cross']])->assertUnprocessable()->assertJsonValidationErrors('signal_types');

        // Pattern fields are prohibited on the other kinds.
        $signalAlert = Alert::factory()->for($user)->create();
        $this->actingAs($user)->patchJson("/api/alerts/{$signalAlert->id}", ['pattern_types' => ['double_top']])->assertUnprocessable()->assertJsonValidationErrors('pattern_types');
        $this->actingAs($user)->patchJson("/api/alerts/{$signalAlert->id}", ['pattern_events' => ['appears']])->assertUnprocessable()->assertJsonValidationErrors('pattern_events');
    }

    public function test_pattern_alerts_are_owner_scoped(): void
    {
        $user = User::factory()->create();
        $foreign = Alert::factory()->watchlistPattern()->create();

        $this->actingAs($user)->patchJson("/api/alerts/{$foreign->id}", ['pattern_types' => ['bull_flag']])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/alerts/{$foreign->id}")->assertNotFound();
        $this->assertSame(['double_bottom'], $foreign->fresh()->pattern_types);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/alerts', ['kind' => 'watchlist_pattern', 'pattern_types' => ['bull_flag']])->assertUnauthorized();
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
