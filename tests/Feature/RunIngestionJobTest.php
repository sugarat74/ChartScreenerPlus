<?php

namespace Tests\Feature;

use App\Enums\IngestionRunStatus;
use App\Jobs\RunIngestionJob;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Ingestion\IngestionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunIngestionJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a `sp500` universe containing the given tickers.
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

    /**
     * A fake engine response with the given number of valid bars.
     *
     * @return array<string, mixed>
     */
    private function bars(string $symbol, int $count): array
    {
        $bars = [];

        for ($i = 0; $i < $count; $i++) {
            $bars[] = [
                'date' => Carbon::parse('2026-09-01')->addDays($i)->toDateString(),
                'open' => 100.0 + $i,
                'high' => 110.0 + $i,
                'low' => 90.0 + $i,
                'close' => 105.0 + $i,
                'volume' => 1_000_000 + $i,
            ];
        }

        return ['symbol' => $symbol, 'bars' => $bars];
    }

    /**
     * Prepare a queued run through the runner and return its id + instrument ids.
     *
     * @return array{0: IngestionRun, 1: list<int>}
     */
    private function prepareRun(IngestionRunner $runner): array
    {
        $prepared = $runner->prepareUniverseRun('sp500');

        return [$prepared['run'], $prepared['instrumentIds']];
    }

    public function test_the_run_is_running_while_the_engine_is_called_and_finishes_completed(): void
    {
        $this->universeWith(['AAA', 'BBB']);

        $runner = app(IngestionRunner::class);
        [$run, $instrumentIds] = $this->prepareRun($runner);

        // A queued run has no started_at until the job picks it up.
        $this->assertSame(IngestionRunStatus::Queued, $run->status);
        $this->assertNull($run->started_at);

        $runId = $run->id;
        $observedStatus = null;
        $observedStartedAt = null;

        Http::fake([
            '*/eod/AAA' => function () use ($runId, &$observedStatus, &$observedStartedAt) {
                $current = IngestionRun::query()->findOrFail($runId);
                $observedStatus = $current->status;
                $observedStartedAt = $current->started_at;

                return Http::response($this->bars('AAA', 2));
            },
            '*/eod/BBB' => Http::response($this->bars('BBB', 3)),
        ]);

        RunIngestionJob::dispatchSync($runId, $instrumentIds);

        // The engine call observed the run mid-flight.
        $this->assertSame(IngestionRunStatus::Running, $observedStatus);
        $this->assertNotNull($observedStartedAt);

        $finished = $run->fresh();

        $this->assertSame(IngestionRunStatus::Completed, $finished->status);
        $this->assertNotNull($finished->started_at);
        $this->assertNotNull($finished->finished_at);
        $this->assertSame(2, $finished->total);
        $this->assertSame(2, $finished->succeeded);
        $this->assertSame(0, $finished->failed);
        $this->assertSame(2, $finished->items()->count());
        $this->assertDatabaseCount('daily_bars', 5);
        Http::assertSentCount(2);
    }

    public function test_a_mixed_run_finishes_partial(): void
    {
        $runner = app(IngestionRunner::class);
        $this->universeWith(['AAA', 'BBB']);
        [$run, $instrumentIds] = $this->prepareRun($runner);

        Http::fake([
            '*/eod/AAA' => Http::response($this->bars('AAA', 2)),
            '*/eod/BBB' => Http::response(['detail' => 'Upstream failed.'], 502),
        ]);

        RunIngestionJob::dispatchSync($run->id, $instrumentIds);

        $finished = $run->fresh();

        $this->assertSame(IngestionRunStatus::Partial, $finished->status);
        $this->assertSame(1, $finished->succeeded);
        $this->assertSame(1, $finished->failed);
        $this->assertNotNull($finished->started_at);
        $this->assertNotNull($finished->finished_at);

        $failedItem = $finished->items()->where('bars_stored', 0)->sole();
        $this->assertIsString($failedItem->message);
        $this->assertNotSame('', $failedItem->message);
    }

    public function test_a_run_where_every_instrument_fails_finishes_failed(): void
    {
        $runner = app(IngestionRunner::class);
        $this->universeWith(['AAA', 'BBB']);
        [$run, $instrumentIds] = $this->prepareRun($runner);

        Http::fake([
            '*/eod/AAA' => Http::response(['detail' => 'Upstream failed.'], 502),
            '*/eod/BBB' => Http::response(['detail' => 'Upstream failed.'], 502),
        ]);

        RunIngestionJob::dispatchSync($run->id, $instrumentIds);

        $finished = $run->fresh();

        $this->assertSame(IngestionRunStatus::Failed, $finished->status);
        $this->assertSame(0, $finished->succeeded);
        $this->assertSame(2, $finished->failed);
        $this->assertSame(2, $finished->items()->count());
        $this->assertNotNull($finished->finished_at);
        $this->assertDatabaseCount('daily_bars', 0);
    }

    public function test_the_job_never_retries(): void
    {
        $job = new RunIngestionJob(1, [1, 2]);

        $this->assertSame(1, $job->tries);
    }

    public function test_the_job_owns_the_run_lifecycle_through_the_runner(): void
    {
        $runner = app(IngestionRunner::class);
        $this->universeWith(['AAA']);
        [$run, $instrumentIds] = $this->prepareRun($runner);

        Http::fake([
            '*/eod/AAA' => Http::response($this->bars('AAA', 1)),
        ]);

        (new RunIngestionJob($run->id, $instrumentIds))->handle($runner);

        $finished = $run->fresh();

        $this->assertSame(IngestionRunStatus::Completed, $finished->status);
        $this->assertSame(1, $finished->succeeded);
        $this->assertSame(1, $finished->items()->count());
    }
}
