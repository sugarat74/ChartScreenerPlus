<?php

namespace Tests\Feature;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Jobs\RunIngestionJob;
use App\Models\IngestionRun;
use App\Models\IngestionRunItem;
use App\Models\Instrument;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminIngestionApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Match the dev SPA origin so Sanctum resolves the session user for the
     * admin middleware; the API, not the SPA, is the enforcement point.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', 'localhost:5173']]);

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Build the `sp500` universe with the given tickers.
     *
     * @param  array<int, string>  $tickers
     * @return array{0: Universe, 1: array<string, Instrument>}
     */
    private function universeWith(array $tickers): array
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instruments = [];

        foreach ($tickers as $ticker) {
            $instruments[$ticker] = Instrument::factory()->create(['ticker' => $ticker]);
        }

        $universe->instruments()->attach(
            array_map(fn (Instrument $instrument): int => $instrument->id, $instruments),
        );

        return [$universe, $instruments];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Every admin ingestion endpoint under test.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function endpoints(): array
    {
        return [
            ['GET', '/api/admin/ingestion/runs'],
            ['POST', '/api/admin/ingestion/runs'],
            ['GET', '/api/admin/ingestion/runs/1'],
            ['POST', '/api/admin/ingestion/runs/1/retry'],
        ];
    }

    public function test_a_guest_is_rejected_from_every_admin_ingestion_endpoint(): void
    {
        foreach ($this->endpoints() as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_a_registered_non_admin_is_forbidden_from_every_admin_ingestion_endpoint(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isAdmin());

        foreach ($this->endpoints() as [$method, $uri]) {
            $this->actingAs($user)->json($method, $uri)->assertForbidden();
        }
    }

    public function test_an_admin_trigger_creates_a_queued_run_and_dispatches_the_job(): void
    {
        Queue::fake();

        [, $instruments] = $this->universeWith(['AAA', 'BBB']);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/ingestion/runs', ['universe' => 'sp500']);

        $response
            ->assertStatus(202)
            ->assertJsonPath('run.status', IngestionRunStatus::Queued->value)
            ->assertJsonPath('run.total', 2)
            ->assertJsonPath('run.succeeded', 0)
            ->assertJsonPath('run.failed', 0)
            ->assertJsonPath('run.started_at', null)
            ->assertJsonPath('run.universe.slug', 'sp500');

        $run = IngestionRun::query()->sole();

        $this->assertSame(IngestionRunStatus::Queued, $run->status);
        $this->assertNull($run->started_at);
        $this->assertSame(0, $run->items()->count());

        $expectedIds = [$instruments['AAA']->id, $instruments['BBB']->id];

        Queue::assertPushed(
            RunIngestionJob::class,
            fn (RunIngestionJob $job): bool => $job->runId === $run->id
                && $job->instrumentIds === $expectedIds,
        );
    }

    public function test_the_trigger_defaults_to_the_configured_universe(): void
    {
        Queue::fake();

        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instrument = Instrument::factory()->create(['ticker' => 'AAA']);
        $universe->instruments()->attach($instrument->id);

        config(['ingestion.universe' => 'sp500']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/ingestion/runs')
            ->assertStatus(202)
            ->assertJsonPath('run.universe.slug', 'sp500')
            ->assertJsonPath('run.total', 1);

        Queue::assertPushed(RunIngestionJob::class);
    }

    public function test_an_unknown_universe_is_rejected_without_creating_a_run(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/ingestion/runs', ['universe' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('universe');

        $this->assertDatabaseCount('ingestion_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_retry_creates_a_new_run_with_only_the_failed_instruments(): void
    {
        Queue::fake();

        [$universe, $instruments] = $this->universeWith(['AAA', 'BBB']);

        $original = IngestionRun::factory()->partial()->create([
            'universe_id' => $universe->id,
            'total' => 2,
            'succeeded' => 1,
            'failed' => 1,
        ]);

        IngestionRunItem::factory()->create([
            'ingestion_run_id' => $original->id,
            'instrument_id' => $instruments['AAA']->id,
            'status' => IngestionRunItemStatus::Success,
            'bars_stored' => 3,
        ]);

        IngestionRunItem::factory()->failed()->create([
            'ingestion_run_id' => $original->id,
            'instrument_id' => $instruments['BBB']->id,
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/admin/ingestion/runs/{$original->id}/retry");

        $response
            ->assertStatus(202)
            ->assertJsonPath('run.status', IngestionRunStatus::Queued->value)
            ->assertJsonPath('run.total', 1)
            ->assertJsonPath('run.universe.id', $universe->id);

        $this->assertDatabaseCount('ingestion_runs', 2);

        $retry = IngestionRun::query()->whereKeyNot($original->id)->sole();

        $this->assertSame(IngestionRunStatus::Queued, $retry->status);
        $this->assertSame($universe->id, $retry->universe_id);
        $this->assertSame(0, $retry->items()->count());

        Queue::assertPushed(
            RunIngestionJob::class,
            fn (RunIngestionJob $job): bool => $job->runId === $retry->id
                && $job->instrumentIds === [$instruments['BBB']->id],
        );
    }

    public function test_retry_is_rejected_when_the_run_has_no_failed_instruments(): void
    {
        Queue::fake();

        [$universe, $instruments] = $this->universeWith(['AAA']);

        $run = IngestionRun::factory()->create([
            'universe_id' => $universe->id,
            'status' => IngestionRunStatus::Completed,
            'total' => 1,
            'succeeded' => 1,
            'failed' => 0,
        ]);

        IngestionRunItem::factory()->create([
            'ingestion_run_id' => $run->id,
            'instrument_id' => $instruments['AAA']->id,
            'status' => IngestionRunItemStatus::Success,
            'bars_stored' => 3,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/ingestion/runs/{$run->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('run');

        $this->assertDatabaseCount('ingestion_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_retry_is_rejected_while_the_run_is_still_running(): void
    {
        Queue::fake();

        [$universe, $instruments] = $this->universeWith(['AAA']);

        $run = IngestionRun::factory()->running()->create([
            'universe_id' => $universe->id,
            'total' => 1,
        ]);

        IngestionRunItem::factory()->failed()->create([
            'ingestion_run_id' => $run->id,
            'instrument_id' => $instruments['AAA']->id,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/ingestion/runs/{$run->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('run');

        $this->assertDatabaseCount('ingestion_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_retry_returns_not_found_for_an_unknown_run(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/ingestion/runs/999/retry')
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_run_detail_includes_the_per_instrument_log(): void
    {
        [$universe, $instruments] = $this->universeWith(['AAA', 'BBB']);

        $run = IngestionRun::factory()->partial()->create([
            'universe_id' => $universe->id,
            'total' => 2,
            'succeeded' => 1,
            'failed' => 1,
        ]);

        IngestionRunItem::factory()->create([
            'ingestion_run_id' => $run->id,
            'instrument_id' => $instruments['BBB']->id,
            'status' => IngestionRunItemStatus::Success,
            'bars_stored' => 5,
            'message' => null,
        ]);

        IngestionRunItem::factory()->failed()->create([
            'ingestion_run_id' => $run->id,
            'instrument_id' => $instruments['AAA']->id,
            'message' => 'Upstream EOD source failed.',
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/ingestion/runs/{$run->id}");

        $response
            ->assertOk()
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.status', IngestionRunStatus::Partial->value)
            ->assertJsonPath('run.universe.slug', 'sp500')
            ->assertJsonCount(2, 'run.items')
            // Items are ordered by instrument ticker (AAA before BBB).
            ->assertJsonPath('run.items.0.ticker', 'AAA')
            ->assertJsonPath('run.items.0.status', IngestionRunItemStatus::Failed->value)
            ->assertJsonPath('run.items.0.bars_stored', 0)
            ->assertJsonPath('run.items.0.message', 'Upstream EOD source failed.')
            ->assertJsonPath('run.items.1.ticker', 'BBB')
            ->assertJsonPath('run.items.1.status', IngestionRunItemStatus::Success->value)
            ->assertJsonPath('run.items.1.bars_stored', 5)
            ->assertJsonPath('run.items.1.message', null);
    }

    public function test_the_run_detail_returns_not_found_for_an_unknown_run(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/ingestion/runs/999')
            ->assertNotFound();
    }

    public function test_the_run_list_returns_the_newest_runs_first_without_items(): void
    {
        [$universe] = $this->universeWith(['AAA']);

        $older = IngestionRun::factory()->create([
            'universe_id' => $universe->id,
            'status' => IngestionRunStatus::Completed,
        ]);
        $newer = IngestionRun::factory()->create([
            'universe_id' => $universe->id,
            'status' => IngestionRunStatus::Failed,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/ingestion/runs?limit=20');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.id', $newer->id)
            ->assertJsonPath('runs.1.id', $older->id)
            ->assertJsonPath('runs.0.status', IngestionRunStatus::Failed->value)
            ->assertJsonPath('runs.0.universe.slug', 'sp500');

        $response->assertJsonMissingPath('runs.0.items');
    }
}
