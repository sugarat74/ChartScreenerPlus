<?php

namespace Tests\Feature;

use App\Enums\IngestionRunItemStatus;
use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestionRunTest extends TestCase
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

    public function test_a_run_over_a_universe_completes_and_records_success_items(): void
    {
        [$universe, $instruments] = $this->universeWith(['AAA', 'BBB']);

        Http::fake([
            '*/eod/AAA' => Http::response($this->bars('AAA', 2)),
            '*/eod/BBB' => Http::response($this->bars('BBB', 3)),
        ]);

        $this->artisan('ingestion:run')->assertExitCode(0);

        $run = IngestionRun::query()->sole();

        $this->assertSame(IngestionRunStatus::Completed, $run->status);
        $this->assertSame($universe->id, $run->universe_id);
        $this->assertSame(2, $run->total);
        $this->assertSame(2, $run->succeeded);
        $this->assertSame(0, $run->failed);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, $run->items()->count());

        $aaaItem = $run->items()->where('instrument_id', $instruments['AAA']->id)->sole();
        $this->assertSame(IngestionRunItemStatus::Success, $aaaItem->status);
        $this->assertSame(2, $aaaItem->bars_stored);
        $this->assertSame('Consulta EOD completada: 2 barras almacenadas.', $aaaItem->message);

        $bbbItem = $run->items()->where('instrument_id', $instruments['BBB']->id)->sole();
        $this->assertSame(IngestionRunItemStatus::Success, $bbbItem->status);
        $this->assertSame(3, $bbbItem->bars_stored);
        $this->assertSame('Consulta EOD completada: 3 barras almacenadas.', $bbbItem->message);

        $this->assertDatabaseCount('daily_bars', 5);
        Http::assertSentCount(2);
    }

    public function test_a_mixed_run_is_partial_and_records_the_failure(): void
    {
        [, $instruments] = $this->universeWith(['AAA', 'BBB']);

        Http::fake([
            '*/eod/AAA' => Http::response($this->bars('AAA', 2)),
            '*/eod/BBB' => Http::response(['detail' => 'Upstream failed.'], 502),
        ]);

        $this->artisan('ingestion:run')->assertExitCode(0);

        $run = IngestionRun::query()->sole();

        $this->assertSame(IngestionRunStatus::Partial, $run->status);
        $this->assertSame(2, $run->total);
        $this->assertSame(1, $run->succeeded);
        $this->assertSame(1, $run->failed);

        $successItem = $run->items()->where('instrument_id', $instruments['AAA']->id)->sole();
        $this->assertSame(IngestionRunItemStatus::Success, $successItem->status);
        $this->assertSame(2, $successItem->bars_stored);

        $failedItem = $run->items()->where('instrument_id', $instruments['BBB']->id)->sole();
        $this->assertSame(IngestionRunItemStatus::Failed, $failedItem->status);
        $this->assertSame(0, $failedItem->bars_stored);
        $this->assertIsString($failedItem->message);
        $this->assertNotSame('', $failedItem->message);

        // The successful instrument persisted its bars; the failed one wrote nothing.
        $this->assertDatabaseCount('daily_bars', 2);
        $this->assertDatabaseMissing('daily_bars', ['instrument_id' => $instruments['BBB']->id]);
    }

    public function test_a_run_where_every_instrument_fails_is_failed(): void
    {
        [, $instruments] = $this->universeWith(['AAA', 'BBB']);

        Http::fake([
            '*/eod/AAA' => Http::response(['detail' => 'Upstream failed.'], 502),
            '*/eod/BBB' => Http::response(['detail' => 'Upstream failed.'], 502),
        ]);

        $this->artisan('ingestion:run')->assertExitCode(1);

        $run = IngestionRun::query()->sole();

        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame(2, $run->total);
        $this->assertSame(0, $run->succeeded);
        $this->assertSame(2, $run->failed);
        $this->assertSame(2, $run->items()->count());

        foreach ([$instruments['AAA'], $instruments['BBB']] as $instrument) {
            $item = $run->items()->where('instrument_id', $instrument->id)->sole();
            $this->assertSame(IngestionRunItemStatus::Failed, $item->status);
            $this->assertIsString($item->message);
            $this->assertNotSame('', $item->message);
        }

        $this->assertDatabaseCount('daily_bars', 0);
    }

    public function test_retry_creates_a_new_run_with_only_the_failed_instruments(): void
    {
        [$universe, $instruments] = $this->universeWith(['AAA', 'BBB']);

        // BBB fails on the first run, succeeds on the retry; AAA always succeeds.
        Http::fake([
            '*/eod/AAA' => Http::response($this->bars('AAA', 2)),
            '*/eod/BBB' => Http::sequence()
                ->push(['detail' => 'Upstream failed.'], 502)
                ->push($this->bars('BBB', 4)),
        ]);

        $this->artisan('ingestion:run')->assertExitCode(0);

        $original = IngestionRun::query()->sole();
        $this->assertSame(IngestionRunStatus::Partial, $original->status);

        $this->artisan('ingestion:run', ['--retry' => $original->id])->assertExitCode(0);

        $this->assertSame(2, IngestionRun::query()->count());

        $retry = IngestionRun::query()->whereKeyNot($original->id)->sole();

        $this->assertSame(IngestionRunStatus::Completed, $retry->status);
        $this->assertSame($universe->id, $retry->universe_id);
        $this->assertSame(1, $retry->total);
        $this->assertSame(1, $retry->succeeded);
        $this->assertSame(0, $retry->failed);

        $this->assertSame(1, $retry->items()->count());

        $retryItem = $retry->items()->sole();
        $this->assertSame($instruments['BBB']->id, $retryItem->instrument_id);
        $this->assertSame(IngestionRunItemStatus::Success, $retryItem->status);
        $this->assertSame(4, $retryItem->bars_stored);

        // The succeeded instrument was not reprocessed into the retry run.
        $this->assertTrue(
            $retry->items()->where('instrument_id', $instruments['AAA']->id)->doesntExist(),
        );

        // AAA was requested only once across both runs; BBB twice (fail + success).
        Http::assertSentCount(3);
        $aaaRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/eod/AAA'));
        $this->assertCount(1, $aaaRequests);
    }

    public function test_unknown_universe_fails_without_creating_a_run(): void
    {
        Http::fake();

        $this->artisan('ingestion:run', ['--universe' => 'nope'])
            ->expectsOutputToContain('No universe found for [nope].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('ingestion_runs', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_retry_run_fails_without_creating_a_run(): void
    {
        Http::fake();

        $this->artisan('ingestion:run', ['--retry' => 999])
            ->expectsOutputToContain('No ingestion run found for id [999].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('ingestion_runs', 0);
        Http::assertNothingSent();
    }
}
