<?php

namespace Tests\Feature;

use App\Jobs\RunMarketContextCommand;
use App\Models\MacroSeries;
use App\Models\User;
use App\Services\MarketContext\MarketContextRunLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * Admin Market Context status and reruns (docs/specs/market-context-ui.md,
 * C3-C4; Scenario 8).
 */
class AdminMarketContextTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_both_endpoints_are_401_for_guests_and_403_for_users(): void
    {
        $this->getJson('/api/admin/market-context')->assertUnauthorized();
        $this->postJson('/api/admin/market-context/runs', ['command' => 'market-regime:compute'])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/market-context')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/market-context/runs', ['command' => 'market-regime:compute'])->assertForbidden();
    }

    public function test_status_on_an_empty_database(): void
    {
        $body = $this->actingAs($this->admin)->getJson('/api/admin/market-context')->assertOk()->json();

        $this->assertSame(['public', 'macro_enabled', 'runs', 'snapshots', 'series'], array_keys($body));
        $this->assertFalse($body['public']);
        $this->assertTrue($body['macro_enabled']);
        $this->assertSame(['market-context:compute' => null, 'macro:refresh' => null, 'market-regime:compute' => null], $body['runs']);
        $this->assertSame(['sectors' => null, 'breadth' => null, 'regime' => null], $body['snapshots']);
        $this->assertSame(array_keys(config('macro.series')), array_column($body['series'], 'code'));
        $this->assertNull($body['series'][0]['last_observation_date']);
        $this->assertFalse($body['series'][0]['stale']);
    }

    public function test_status_on_the_production_like_fixture(): void
    {
        $this->productionLikeFixture();
        $this->regimeRow('2025-10-22', 'favourable', 3, 5);
        $this->regimeRow('2026-01-05', 'insufficient', null, 3);
        MacroSeries::query()->where('code', 'DGS10')->update([
            'last_refreshed_at' => Carbon::parse('2026-10-23 14:00:00', 'UTC'),
            'last_attempted_at' => Carbon::parse('2026-10-23 14:00:00', 'UTC'),
        ]);
        MacroSeries::query()->where('code', 'ICSA')->update(['last_error' => 'unavailable', 'last_observation_date' => '2026-09-26']);

        $body = $this->actingAs($this->admin)->getJson('/api/admin/market-context')->assertOk()->json();

        $this->assertSame(['first_date' => '2026-10-22', 'latest_date' => '2026-10-22', 'latest_rows' => 11], $body['snapshots']['sectors']);
        $this->assertSame(['first_date' => '2026-10-22', 'latest_date' => '2026-10-22'], $body['snapshots']['breadth']);
        $this->assertSame([
            'first_date' => '2025-10-22',
            'latest_date' => '2026-10-22',
            'count' => 3,
            'rules_version' => 1,
            'latest' => ['regime' => 'neutral', 'score' => 1, 'available' => 5],
            'distribution' => ['favourable' => 1, 'neutral' => 1, 'adverse' => 0, 'insufficient' => 1],
        ], $body['snapshots']['regime']);

        $series = collect($body['series'])->keyBy('code');
        $this->assertSame([
            'code' => 'DGS10', 'category' => 'rates', 'frequency' => 'daily', 'license' => 'public_domain', 'enabled' => true,
            'last_observation_date' => '2026-10-21', 'last_refreshed_at' => '2026-10-23T14:00:00Z',
            'last_attempted_at' => '2026-10-23T14:00:00Z', 'last_error' => null, 'stale' => false,
        ], $series['DGS10']);
        // Weekly: 2026-09-26 is 27 days before 10-23 (> 16).
        $this->assertTrue($series['ICSA']['stale']);
        $this->assertSame('unavailable', $series['ICSA']['last_error']);
        $this->assertFalse($series['NFCI']['enabled']);
        $this->assertFalse($series['VIXCLS']['enabled']);
        $this->assertNull($series['NFCI']['last_observation_date']);
    }

    public function test_rerun_queues_each_command_without_options(): void
    {
        Queue::fake();

        foreach (MarketContextRunLog::COMMANDS as $command) {
            $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => $command])
                ->assertStatus(202)
                ->assertJsonPath('command', $command)
                ->assertJsonPath('run.status', 'queued')
                ->assertJsonPath('run.queued_at', '2026-10-23T16:00:00Z');

            Queue::assertPushed(RunMarketContextCommand::class, fn (RunMarketContextCommand $job): bool => $job->artisanCommand === $command);
        }

        $this->actingAs($this->admin)->getJson('/api/admin/market-context')->assertJsonPath('runs.macro:refresh.status', 'queued');
    }

    public function test_rerun_rejects_unknown_commands_and_macro_while_disabled(): void
    {
        Queue::fake();

        foreach (['ingestion:pipeline', 'market-regime:compute --from=2020-01-01', '', null] as $command) {
            $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => $command])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['command']);
        }

        config(['macro.enabled' => false]);
        $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => 'macro:refresh'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.command.0', 'Macro series are disabled (MACRO_ENABLED=false).');

        Queue::assertNothingPushed();
    }

    public function test_rerun_is_409_while_queued_or_running_and_allowed_once_interrupted(): void
    {
        Queue::fake();
        $post = fn () => $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => 'market-regime:compute']);

        $post()->assertStatus(202);
        $post()->assertStatus(409)->assertJsonPath('message', 'This command is already queued or running.');

        MarketContextRunLog::started('market-regime:compute');
        $post()->assertStatus(409);

        // 31 minutes later the record is interrupted and no longer blocks.
        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $this->actingAs($this->admin)->getJson('/api/admin/market-context')->assertJsonPath('runs.market-regime:compute.status', 'interrupted');
        $post()->assertStatus(202)->assertJsonPath('run.status', 'queued');

        Queue::assertPushed(RunMarketContextCommand::class, 2);
    }

    public function test_the_job_runs_the_command_and_records_its_result(): void
    {
        // Sync queue: the command runs inside the request.
        $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => 'market-regime:compute'])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'succeeded')
            ->assertJsonPath('run.queued_at', '2026-10-23T16:00:00Z')
            ->assertJsonPath('run.exit_code', 0);

        // An unknown market-context universe: the compute exits 1.
        config(['market_context.universe' => 'missing']);
        $this->actingAs($this->admin)->postJson('/api/admin/market-context/runs', ['command' => 'market-context:compute'])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('run.exit_code', 1);
    }

    public function test_the_job_closes_a_queued_record_when_the_command_ran_nothing(): void
    {
        config(['macro.fred_api_key' => '']);
        MarketContextRunLog::queued('macro:refresh');

        // A malformed key records its own failure; a lock-held skip would leave `queued`.
        (new RunMarketContextCommand('macro:refresh'))->handle();
        $this->assertSame('failed', MarketContextRunLog::get('macro:refresh')['status']);

        MarketContextRunLog::queued('market-regime:compute');
        (new RunMarketContextCommand('market-regime:compute'))->failed(new \RuntimeException('worker lost'));
        $this->assertSame(['failed', 1], [MarketContextRunLog::get('market-regime:compute')['status'], MarketContextRunLog::get('market-regime:compute')['exit_code']]);
    }
}
