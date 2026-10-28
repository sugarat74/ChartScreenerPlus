<?php

namespace Tests\Feature;

use App\Models\SavedScreener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract tests for the authenticated, self-owned Saved Screeners endpoints.
 *
 * The invariant under test is ownership: every query is scoped to
 * `$request->user()->savedScreeners()`, no endpoint accepts a `user_id`, the
 * stored definition is the canonical twenty-key filter object (including
 * `sort`, the sector context keys of market-context-patterns and the table
 * column keys of screener-table-column-controls), and a
 * duplicate name is a `422` on `errors.name`.
 */
class SavedScreenersApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, canonical filter payload (the seven API param keys).
     */
    private const FILTERS = [
        'signal' => [],
        'rsi_min' => null,
        'rsi_max' => null,
        'min_rvol' => null,
        'price_above_sma200' => false,
        'ma_cross' => null,
        'sort' => 'rvol_desc',
    ];

    /**
     * The table column keys (screener-table-column-controls) when off.
     */
    private const COLUMN_NULLS = [
        'search' => null,
        'sector' => null,
        'industry' => null,
        'country' => null,
        'rvol_max' => null,
        'close_min' => null,
        'close_max' => null,
        'change_min' => null,
        'change_max' => null,
    ];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Momentum', 'filters' => self::FILTERS], $overrides);
    }

    public function test_saved_screeners_accept_the_six_expansion_signal_types(): void
    {
        $user = User::factory()->create();
        $filters = self::FILTERS;
        $filters['signal'] = ['stoch_bullish_cross', 'bollinger_squeeze', 'golden_cross'];

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Expansion', 'filters' => $filters])
            ->assertCreated()
            ->assertJsonPath('screener.filters.signal', ['golden_cross', 'bollinger_squeeze', 'stoch_bullish_cross']);

        $filters['signal'] = ['bogus'];
        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Bad', 'filters' => $filters])
            ->assertUnprocessable();
    }

    public function test_a_guest_is_rejected_from_every_saved_screener_route(): void
    {
        $this->getJson('/api/screeners')->assertUnauthorized();
        $this->postJson('/api/screeners', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/screeners/1')->assertUnauthorized();

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_a_user_can_save_a_screener_and_the_canonical_filters_are_stored(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        $filters['signal'] = ['macd_bearish_cross', 'golden_cross', 'golden_cross'];
        $filters['rsi_min'] = 30;
        $filters['min_rvol'] = 2;
        $filters['price_above_sma200'] = true;
        $filters['sort'] = 'rsi_desc';

        $this->actingAs($user)
            ->postJson('/api/screeners', [
                'name' => 'Cruce dorado RVOL',
                'filters' => $filters,
            ])
            ->assertCreated()
            ->assertJsonPath('screener.name', 'Cruce dorado RVOL')
            ->assertJsonPath('screener.filters.signal', ['golden_cross', 'macd_bearish_cross'])
            ->assertJsonPath('screener.filters.min_rvol', 2)
            ->assertJsonPath('screener.filters.sort', 'rsi_desc')
            ->assertJsonStructure([
                'screener' => ['id', 'name', 'filters' => [
                    'signal', 'rsi_min', 'rsi_max', 'min_rvol', 'price_above_sma200', 'ma_cross', 'pattern', 'pattern_status', 'sort',
                    'sector_trend', 'sector_rs_rank_max',
                ]],
            ]);

        $this->assertDatabaseCount('saved_screeners', 1);

        $saved = SavedScreener::query()->firstOrFail();
        $this->assertSame($user->id, $saved->user_id);
        $this->assertSame('Cruce dorado RVOL', $saved->name);
        $this->assertSame([
            'signal' => ['golden_cross', 'macd_bearish_cross'],
            'rsi_min' => 30,
            'rsi_max' => null,
            'min_rvol' => 2,
            'price_above_sma200' => true,
            'ma_cross' => null,
            'pattern' => [],
            'pattern_status' => 'any',
            'sort' => 'rsi_desc',
            'sector_trend' => null,
            'sector_rs_rank_max' => null,
            ...self::COLUMN_NULLS,
        ], $saved->filters);
    }

    public function test_an_empty_list_returns_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertExactJson(['screeners' => []]);
    }

    public function test_the_list_is_ordered_by_name(): void
    {
        $user = User::factory()->create();
        SavedScreener::factory()->for($user)->create(['name' => 'zeta']);
        SavedScreener::factory()->for($user)->create(['name' => 'alpha']);
        SavedScreener::factory()->for($user)->create(['name' => 'momentum']);

        $this->actingAs($user)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertJsonPath('screeners.0.name', 'alpha')
            ->assertJsonPath('screeners.1.name', 'momentum')
            ->assertJsonPath('screeners.2.name', 'zeta');
    }

    public function test_ownership_is_enforced_across_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $saved = SavedScreener::factory()->for($owner)->create(['name' => 'Momentum']);

        // The other user sees an empty list, not the owner's Screener.
        $this->actingAs($other)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertExactJson(['screeners' => []]);

        // Deleting the owner's id is a 404 for the other user and changes
        // nothing for the owner.
        $this->actingAs($other)
            ->deleteJson("/api/screeners/{$saved->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Screener not found.');

        $this->assertDatabaseHas('saved_screeners', [
            'id' => $saved->id,
            'user_id' => $owner->id,
        ]);
        $this->assertDatabaseCount('saved_screeners', 1);
    }

    public function test_a_client_supplied_user_id_is_ignored(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['user_id' => $victim->id]))
            ->assertCreated();

        $this->assertDatabaseHas('saved_screeners', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('saved_screeners', ['user_id' => $victim->id]);
    }

    public function test_a_duplicate_name_is_rejected_per_user_but_allowed_for_another(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->postJson('/api/screeners', $this->payload())->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('saved_screeners', 1);

        // A second user may reuse the same name.
        $this->actingAs($other)->postJson('/api/screeners', $this->payload())->assertCreated();

        $this->assertDatabaseCount('saved_screeners', 2);
    }

    public function test_the_name_is_trimmed_before_storage_and_uniqueness(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['name' => '  Momentum  ']))
            ->assertCreated()
            ->assertJsonPath('screener.name', 'Momentum');

        $this->assertDatabaseHas('saved_screeners', ['name' => 'Momentum']);

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['name' => 'Momentum']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_missing_blank_or_non_string_name_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/screeners', ['filters' => self::FILTERS])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['name' => '   ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['name' => ['Momentum']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson('/api/screeners', $this->payload(['name' => str_repeat('a', 61)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_an_unknown_filter_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        $filters['unknown'] = 'nope';

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_a_missing_canonical_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        unset($filters['sort']);

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.sort');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_an_unknown_signal_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        $filters['signal'] = ['not_a_signal'];

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.signal.0');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        $filters['rsi_min'] = 150;

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.rsi_min');

        $filters = self::FILTERS;
        $filters['min_rvol'] = -1;

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.min_rvol');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_an_unknown_sort_is_rejected(): void
    {
        $user = User::factory()->create();

        $filters = self::FILTERS;
        $filters['sort'] = 'confidence_desc';

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Momentum', 'filters' => $filters])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.sort');

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_a_screener_can_be_deleted_and_then_is_not_found(): void
    {
        $user = User::factory()->create();
        $saved = SavedScreener::factory()->for($user)->create(['name' => 'Momentum']);

        $this->actingAs($user)
            ->deleteJson("/api/screeners/{$saved->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('saved_screeners', ['id' => $saved->id]);

        $this->actingAs($user)
            ->deleteJson("/api/screeners/{$saved->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Screener not found.');
    }

    public function test_an_unknown_screener_id_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/screeners/99999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Screener not found.');
    }

    public function test_deleting_a_user_cascades_their_saved_screeners(): void
    {
        $user = User::factory()->create();
        SavedScreener::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_sector_context_keys_round_trip_as_an_eleven_key_object(): void
    {
        config(['market_context.public' => true]);
        $user = User::factory()->create();
        $filters = self::FILTERS + ['sector_trend' => 'uptrend', 'sector_rs_rank_max' => 3];

        $this->actingAs($user)->postJson('/api/screeners', $this->payload(['filters' => $filters]))
            ->assertCreated()
            ->assertJsonPath('screener.filters.sector_trend', 'uptrend')
            ->assertJsonPath('screener.filters.sector_rs_rank_max', 3);

        $listed = $this->actingAs($user)->getJson('/api/screeners')->assertOk()->json('screeners.0.filters');
        $this->assertSame([
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any', 'sort' => 'rvol_desc',
            'sector_trend' => 'uptrend', 'sector_rs_rank_max' => 3,
            ...self::COLUMN_NULLS,
        ], $listed);
    }

    public function test_a_legacy_row_returns_both_sector_keys_as_null(): void
    {
        $user = User::factory()->create();
        $user->savedScreeners()->create(['name' => 'Old', 'filters' => self::FILTERS]);

        $filters = $this->actingAs($user)->getJson('/api/screeners')->assertOk()->json('screeners.0.filters');

        $this->assertCount(20, $filters);
        $this->assertNull($filters['sector_trend']);
        $this->assertNull($filters['sector_rs_rank_max']);
        // screener-table-column-controls: rows saved before it read as off.
        $this->assertSame(self::COLUMN_NULLS, array_intersect_key($filters, self::COLUMN_NULLS));
    }

    public function test_invalid_sector_context_values_are_422(): void
    {
        config(['market_context.public' => true]);
        $user = User::factory()->create();

        foreach ([['sector_trend' => 'sideways'], ['sector_trend' => ['uptrend']], ['sector_rs_rank_max' => 0],
            ['sector_rs_rank_max' => 12], ['sector_rs_rank_max' => 3.5], ['sector_rs_rank_max' => 'abc']] as $override) {
            $this->actingAs($user)->postJson('/api/screeners', $this->payload(['filters' => array_merge(self::FILTERS, $override)]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['filters.'.array_key_first($override)]);
        }

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_a_user_without_market_context_access_cannot_save_sector_filters(): void
    {
        config(['market_context.public' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/screeners', $this->payload(['filters' => self::FILTERS + ['sector_trend' => 'uptrend']]))
            ->assertForbidden()
            ->assertExactJson(['message' => 'The sector context filters are not available for your account.']);
        $this->assertDatabaseCount('saved_screeners', 0);

        // Null sector keys need no access.
        $this->actingAs($user)->postJson('/api/screeners', $this->payload(['filters' => self::FILTERS + ['sector_trend' => null, 'sector_rs_rank_max' => null]]))
            ->assertCreated();

        // An Admin previews them while the flag is off.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/screeners', $this->payload(['filters' => self::FILTERS + ['sector_rs_rank_max' => 11]]))
            ->assertCreated()
            ->assertJsonPath('screener.filters.sector_rs_rank_max', 11);
    }
}
