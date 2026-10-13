<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\ForecastPrediction;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Forecast\ForecastStateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function instrumentWithHistory(): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $date = Carbon::parse('2026-01-05');
        for ($i = 0; $i < 50; $i++) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => $date->format('Y-m-d'),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000,
            ]);
            $date->addDay();
            while ($date->isWeekend()) {
                $date->addDay();
            }
        }
        $latestDate = $instrument->dailyBars()->orderByDesc('date')->first()->date->format('Y-m-d');
        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => $latestDate,
            'rsi14' => 55, 'sma50' => 100,
        ]);

        return $instrument;
    }

    private function enableProvider(): void
    {
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
    }

    public function test_guest_and_non_admin_cannot_use_protected_routes(): void
    {
        $this->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 60])->assertUnauthorized();
        $this->getJson('/api/admin/forecasts/report')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/forecasts/report')->assertForbidden();
    }

    public function test_deduplication_threshold_and_provider_payload_cutoff(): void
    {
        $instrument = $this->instrumentWithHistory();
        $this->enableProvider();
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['direction' => ['type' => 'choice', 'choice' => 'up', 'probabilities' => ['up' => 0.62, 'down' => 0.28, 'flat' => 0.10]]],
        ])]);
        $user = User::factory()->create();
        $first = $this->actingAs($user)->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 70]);
        $first->assertOk()->assertJsonPath('forecast.signal', null)->assertJsonPath('forecast.probabilities.up', 0.62);
        $second = $this->actingAs($user)->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 60]);
        $second->assertOk()->assertJsonPath('forecast.signal', 'up');
        $this->assertDatabaseCount('forecast_predictions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), $user->email)
            && count($request['state']['bars']) === 50
            && $request['state']['bars'][49]['date'] === $first->json('forecast.as_of_date'));

        $this->actingAs($user)->postJson('/api/instruments/TEST/forecast', ['horizon' => 2, 'minimum_probability' => 50])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 101])->assertUnprocessable();
        $this->assertEquals($instrument->id, ForecastPrediction::first()->instrument_id);
    }

    public function test_missing_inputs_and_provider_failures_never_create_prediction(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $this->actingAs(User::factory()->create())->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 60])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_data');
        $this->assertDatabaseCount('forecast_predictions', 0);

        $instrument->delete();
        $this->instrumentWithHistory();
        $this->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 60])
            ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
        $this->enableProvider();
        Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => []])]);
        $this->postJson('/api/instruments/TEST/forecast', ['horizon' => 1, 'minimum_probability' => 60])
            ->assertStatus(503)->assertJsonPath('code', 'invalid_provider_response');
        $this->assertDatabaseCount('forecast_predictions', 0);
    }

    public function test_historical_state_excludes_future_bars(): void
    {
        $instrument = $this->instrumentWithHistory();
        $builder = app(ForecastStateBuilder::class);
        $cut = $instrument->dailyBars()->orderByDesc('date')->first()->date->format('Y-m-d');
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => Carbon::parse($cut)->addDay()->format('Y-m-d')]);
        $state = $builder->build($instrument, $cut);
        $this->assertNotNull($state);
        $this->assertSame($cut, $state['as_of_date']);
        $this->assertCount(50, $state['state']['bars']);
    }

    public function test_resolution_and_admin_metrics_ignore_pending_and_are_idempotent(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $prediction = ForecastPrediction::create([
            'instrument_id' => $instrument->id, 'as_of_date' => '2025-01-06', 'reference_close' => 100,
            'horizon' => 1, 'target_date' => '2025-01-07', 'model_version' => 'jev-1.13.0',
            'question_version' => 'close-direction-v1', 'input_hash' => str_repeat('a', 64),
            'probability_up' => .7, 'probability_down' => .2, 'probability_flat' => .1,
            'source' => 'prospective',
        ]);
        $this->artisan('forecast:resolve')->assertExitCode(0);
        $this->assertNull($prediction->fresh()->resolved_at);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2025-01-07', 'close' => 110]);
        $this->artisan('forecast:resolve')->assertExitCode(0);
        $firstResolved = $prediction->fresh()->resolved_at;
        $this->assertSame('up', $prediction->fresh()->observed_class);
        $this->artisan('forecast:resolve')->assertExitCode(0);
        $this->assertEquals($firstResolved, $prediction->fresh()->resolved_at);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->actingAs($admin)->getJson('/api/admin/forecasts/report?minimum_probability=60')
            ->assertOk()->assertJsonPath('groups.0.sample', 1)
            ->assertJsonPath('groups.0.pending', 0)
            ->assertJsonPath('groups.0.coverage', 1)
            ->assertJsonPath('groups.0.directional_accuracy', 1);
    }

    public function test_explicit_backfill_keeps_historical_cases_separate(): void
    {
        $instrument = $this->instrumentWithHistory();
        $this->enableProvider();
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['direction' => ['type' => 'choice', 'choice' => 'flat', 'probabilities' => ['up' => .2, 'down' => .2, 'flat' => .6]]],
        ])]);
        $date = $instrument->dailyBars()->orderByDesc('date')->first()->date->format('Y-m-d');
        $this->artisan("forecast:backfill TEST {$date} {$date} --horizon=1 --limit=1")->assertExitCode(0);
        $this->assertDatabaseHas('forecast_predictions', ['source' => 'historical', 'horizon' => 1]);
        $this->assertNull(ForecastPrediction::first()->resolved_at);
    }

    public function test_five_session_forecast_resolves_from_target_bar_despite_missing_intermediate_bar(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $prediction = ForecastPrediction::create([
            'instrument_id' => $instrument->id, 'as_of_date' => '2026-10-05', 'reference_close' => 100,
            'horizon' => 5, 'target_date' => '2026-10-12', 'model_version' => 'jev-1.13.0',
            'question_version' => 'close-direction-v1', 'input_hash' => str_repeat('b', 64),
            'probability_up' => .7, 'probability_down' => .2, 'probability_flat' => .1,
            'source' => 'prospective',
        ]);
        foreach (['2026-10-06', '2026-10-07', '2026-10-09'] as $date) {
            DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date, 'close' => 90]);
        }
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-12', 'close' => 110]);
        $this->travelTo(Carbon::parse('2026-10-20'));
        $this->artisan('forecast:resolve')->assertExitCode(0);
        $this->assertSame('up', $prediction->fresh()->observed_class);
        $this->assertEquals(110, (float) $prediction->fresh()->observed_close);
        $resolvedAt = $prediction->fresh()->resolved_at;
        $this->artisan('forecast:resolve')->assertExitCode(0);
        $this->assertEquals($resolvedAt, $prediction->fresh()->resolved_at);
    }

    public function test_target_date_respects_committed_holiday_and_refuses_uncovered_years(): void
    {
        $builder = app(ForecastStateBuilder::class);
        $this->assertSame('2026-11-27', $builder->targetDate('2026-11-25', 1));
        $this->artisan('forecast:backfill TEST 2025-01-01 2025-01-31')->assertExitCode(1);
        $this->expectException(\App\Services\Forecast\ForecastUnavailable::class);
        $builder->targetDate('2027-12-31', 1);
    }
}
