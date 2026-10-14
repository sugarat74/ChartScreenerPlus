<?php

namespace Tests\Feature;

use App\Jobs\RunForecastBacktestCase;
use App\Models\DailyBar;
use App\Models\ForecastBacktestCase;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForecastBacktestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $user;
    }

    private function instrument(): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $date = Carbon::parse('2026-01-05');
        for ($i = 0; $i < 53; $i++) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id, 'date' => $date->format('Y-m-d'),
                'open' => 100, 'high' => 111, 'low' => 99,
                'close' => $i === 52 ? 110 : 100, 'volume' => 1000,
            ]);
            if ($i === 50 || $i === 51) {
                IndicatorSnapshot::factory()->create([
                    'instrument_id' => $instrument->id, 'date' => $date->format('Y-m-d'),
                    'rsi14' => 55, 'sma50' => 100,
                ]);
            }
            $date->addWeekday();
        }

        return $instrument;
    }

    public function test_only_admin_can_start_or_view_backtests(): void
    {
        $payload = ['tickers' => ['TEST'], 'bars' => 2];
        $this->postJson('/api/admin/forecasts/backtests', $payload)->assertUnauthorized();
        $this->getJson('/api/admin/forecasts/backtests')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/forecasts/backtests', $payload)->assertForbidden();
        $this->getJson('/api/admin/forecasts/backtests')->assertForbidden();
    }

    public function test_admin_run_uses_each_historical_cutoff_and_reports_accuracy(): void
    {
        $this->instrument();
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        Bus::fake([RunForecastBacktestCase::class]);
        $this->actingAs($this->admin());
        $response = $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['test'], 'bars' => 2]);
        $response->assertStatus(202)->assertJsonPath('run.total', 2);
        $runId = $response->json('run.id');
        Bus::assertDispatchedTimes(RunForecastBacktestCase::class, 2);

        Http::fakeSequence()
            ->push(['model' => 'jev-1.13.0', 'answers' => ['direction' => ['type' => 'choice', 'choice' => 'down', 'probabilities' => ['up' => .2, 'down' => .7, 'flat' => .1]]]])
            ->push(['model' => 'jev-1.13.0', 'answers' => ['direction' => ['type' => 'choice', 'choice' => 'flat', 'probabilities' => ['up' => .2, 'down' => .2, 'flat' => .6]]]]);
        foreach (ForecastBacktestCase::query()->where('run_id', $runId)->orderBy('id')->pluck('id') as $caseId) {
            app()->call([new RunForecastBacktestCase($caseId), 'handle']);
        }
        $this->getJson("/api/admin/forecasts/backtests/{$runId}")
            ->assertOk()->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.processed', 2)->assertJsonPath('run.correct', 1)
            ->assertJsonPath('run.accuracy', .5)->assertJsonPath('run.skipped', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => collect($request['state']['bars'])->every(fn ($bar) => $bar['date'] <= $request['state']['as_of_date']));

        // Running an already completed case cannot spend another call or alter totals.
        $caseId = ForecastBacktestCase::query()->where('run_id', $runId)->first()->id;
        app()->call([new RunForecastBacktestCase($caseId), 'handle']);
        Http::assertSentCount(2);
    }

    public function test_invalid_or_unavailable_input_never_starts_a_run(): void
    {
        $this->instrument();
        $this->actingAs($this->admin());
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST', 'test'], 'bars' => 2])->assertUnprocessable();
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['UNKNOWN'], 'bars' => 2])->assertUnprocessable();
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 101])->assertUnprocessable();
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2])->assertStatus(503);
        $this->assertDatabaseCount('forecast_backtest_runs', 0);
    }

    public function test_admin_can_select_100_stocks_and_page_through_200_cases(): void
    {
        $tickers = [];
        foreach (range(1, 100) as $number) {
            $ticker = sprintf('T%03d', $number);
            $tickers[] = $ticker;
            $instrument = Instrument::factory()->create(['ticker' => $ticker]);
            foreach (['2026-01-05', '2026-01-06', '2026-01-07'] as $date) {
                DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
            }
        }
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        Queue::fake();
        $this->actingAs($this->admin());

        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => [...$tickers, 'OVERLIMIT'], 'bars' => 2])
            ->assertUnprocessable();
        $response = $this->postJson('/api/admin/forecasts/backtests', ['tickers' => $tickers, 'bars' => 2])
            ->assertStatus(202)->assertJsonPath('run.total', 200);
        Queue::assertPushed(RunForecastBacktestCase::class, 200);
        $runId = $response->json('run.id');
        $this->getJson("/api/admin/forecasts/backtests/{$runId}")
            ->assertOk()->assertJsonCount(100, 'run.cases')
            ->assertJsonPath('run.case_page.current', 1)
            ->assertJsonPath('run.case_page.last', 2)
            ->assertJsonPath('run.case_page.total', 200);
        $this->getJson("/api/admin/forecasts/backtests/{$runId}?page=2")
            ->assertOk()->assertJsonCount(100, 'run.cases')
            ->assertJsonPath('run.case_page.current', 2);
    }

    public function test_missing_next_session_is_skipped_without_a_provider_call(): void
    {
        $instrument = $this->instrument();
        $last = $instrument->dailyBars()->orderByDesc('date')->firstOrFail();
        $last->update(['date' => $last->date->copy()->addDay()->format('Y-m-d')]);
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        Bus::fake([RunForecastBacktestCase::class]);
        $this->actingAs($this->admin());
        $response = $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2])->assertStatus(202);
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2])->assertUnprocessable();
        $runId = $response->json('run.id');
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['direction' => ['type' => 'choice', 'choice' => 'flat', 'probabilities' => ['up' => .2, 'down' => .2, 'flat' => .6]]],
        ])]);
        foreach (ForecastBacktestCase::query()->where('run_id', $runId)->orderBy('id')->pluck('id') as $caseId) {
            app()->call([new RunForecastBacktestCase($caseId), 'handle']);
        }
        $this->getJson("/api/admin/forecasts/backtests/{$runId}")
            ->assertOk()->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.skipped', 1)->assertJsonPath('run.processed', 1)
            ->assertJsonPath('run.accuracy', 1);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('forecast_backtest_cases', ['run_id' => $runId, 'reason' => 'missing_target_bar']);
    }

    public function test_model_change_between_jobs_skips_remaining_cases_without_mixing_versions(): void
    {
        $this->instrument();
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        Bus::fake([RunForecastBacktestCase::class]);
        $this->actingAs($this->admin());
        $runId = $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2])
            ->assertStatus(202)->assertJsonPath('run.model_version', 'jev-1.13.0')->json('run.id');
        $caseIds = ForecastBacktestCase::query()->where('run_id', $runId)->orderBy('id')->pluck('id');
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['direction' => ['type' => 'choice', 'choice' => 'up', 'probabilities' => ['up' => .7, 'down' => .2, 'flat' => .1]]],
        ])]);
        app()->call([new RunForecastBacktestCase($caseIds[0]), 'handle']);
        config()->set('forecast.model', 'jev-1.14.0');
        app()->call([new RunForecastBacktestCase($caseIds[1]), 'handle']);
        $this->getJson("/api/admin/forecasts/backtests/{$runId}")
            ->assertOk()->assertJsonPath('run.model_version', 'jev-1.13.0')
            ->assertJsonPath('run.processed', 1)->assertJsonPath('run.skipped', 1)
            ->assertJsonPath('run.status', 'completed');
        $this->assertDatabaseHas('forecast_backtest_cases', ['id' => $caseIds[1], 'reason' => 'version_changed']);
        Http::assertSentCount(1);
    }

    public function test_queue_dispatch_failure_terminates_run_and_allows_a_new_one(): void
    {
        $this->instrument();
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        $this->actingAs($this->admin());
        $dispatches = 0;
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$dispatches): void {
            if (++$dispatches === 2) {
                throw new \RuntimeException('queue unavailable');
            }
        });
        $first = $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2]);
        $first->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.skipped', 2);
        $this->assertDatabaseHas('forecast_backtest_cases', ['run_id' => $first->json('run.id'), 'reason' => 'dispatch_failed']);
        Bus::fake([RunForecastBacktestCase::class]);
        $this->postJson('/api/admin/forecasts/backtests', ['tickers' => ['TEST'], 'bars' => 2])
            ->assertStatus(202)->assertJsonPath('run.status', 'queued');
    }

    public function test_large_run_recovers_from_failure_after_a_bulk_queue_insert(): void
    {
        foreach (['TESTA', 'TESTB'] as $ticker) {
            $instrument = Instrument::factory()->create(['ticker' => $ticker]);
            $date = Carbon::parse('2026-01-05');
            for ($i = 0; $i < 101; $i++) {
                DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date->format('Y-m-d')]);
                $date->addWeekday();
            }
        }
        config()->set('forecast.enabled', true);
        config()->set('forecast.rights_verified', true);
        config()->set('forecast.api_key', 'fixture-key');
        config()->set('forecast.model', 'jev-1.13.0');
        $this->actingAs($this->admin());
        $batches = 0;
        Queue::shouldReceive('bulk')->twice()->andReturnUsing(function () use (&$batches): void {
            if (++$batches === 2) {
                throw new \RuntimeException('queue unavailable');
            }
        });
        $response = $this->postJson('/api/admin/forecasts/backtests', [
            'tickers' => ['TESTA', 'TESTB'], 'bars' => 100,
        ])->assertStatus(202)->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('run.skipped', 200);
        $this->assertDatabaseCount('forecast_backtest_cases', 200);
        $this->assertDatabaseMissing('forecast_backtest_cases', [
            'run_id' => $response->json('run.id'), 'status' => 'queued',
        ]);
    }
}
