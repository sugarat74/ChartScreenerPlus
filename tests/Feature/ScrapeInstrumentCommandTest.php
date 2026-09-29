<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScrapeInstrumentCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function engineBarsResponse(): array
    {
        return [
            'symbol' => 'NVDA',
            'bars' => [
                [
                    'date' => '2026-09-24',
                    'open' => 176.5,
                    'high' => 180.25,
                    'low' => 175.0,
                    'close' => 179.75,
                    'volume' => 120000000,
                ],
                [
                    'date' => '2026-09-25',
                    'open' => 180.0,
                    'high' => 185.5,
                    'low' => 179.0,
                    'close' => 184.25,
                    'volume' => 130000000,
                ],
            ],
        ];
    }

    public function test_it_stores_bars_for_an_existing_instrument(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);

        Http::fake(['*/eod/NVDA' => Http::response($this->engineBarsResponse())]);

        $this->artisan('ingestion:scrape', ['ticker' => 'nvda'])
            ->expectsOutputToContain('Stored 2 bars for NVDA')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_bars', 2);

        $bar = DailyBar::query()
            ->where('instrument_id', $instrument->id)
            ->orderBy('date')
            ->firstOrFail();

        $this->assertSame('2026-09-24', $bar->date->toDateString());
        $this->assertSame('176.5000', $bar->open);
        $this->assertSame('179.7500', $bar->close);
        $this->assertSame(120000000, (int) $bar->volume);
    }

    public function test_re_running_is_idempotent_and_does_not_duplicate_bars(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        Http::fake(['*/eod/NVDA' => Http::response($this->engineBarsResponse())]);

        $this->artisan('ingestion:scrape', ['ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('daily_bars', 2);

        $this->artisan('ingestion:scrape', ['ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('daily_bars', 2);
    }

    public function test_unknown_ticker_fails_without_writing_or_calling_the_engine(): void
    {
        Http::fake();

        $this->artisan('ingestion:scrape', ['ticker' => 'ZZZZ'])
            ->expectsOutputToContain('No instrument found for [ZZZZ]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_bars', 0);
        Http::assertNothingSent();
    }

    public function test_engine_error_is_handled_without_writing(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        Http::fake(['*/eod/NVDA' => Http::response(['detail' => 'Upstream failed.'], 502)]);

        $this->artisan('ingestion:scrape', ['ticker' => 'NVDA'])
            ->expectsOutputToContain('Engine failed for [NVDA]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_bars', 0);
    }

    public function test_empty_engine_response_fails_without_writing(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        Http::fake(['*/eod/NVDA' => Http::response(['symbol' => 'NVDA', 'bars' => []])]);

        $this->artisan('ingestion:scrape', ['ticker' => 'NVDA'])
            ->expectsOutputToContain('no EOD bars')
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_bars', 0);
    }

    public function test_malformed_bars_are_skipped_and_reported(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        Http::fake([
            '*/eod/NVDA' => Http::response([
                'symbol' => 'NVDA',
                'bars' => [
                    [
                        'date' => '2026-09-25',
                        'open' => 1.0,
                        'high' => 2.0,
                        'low' => 0.5,
                        'close' => 1.5,
                        'volume' => 10,
                    ],
                    [
                        'date' => 'not-a-date',
                        'open' => 1.0,
                        'high' => 2.0,
                        'low' => 0.5,
                        'close' => 1.5,
                        'volume' => 10,
                    ],
                    'nonsense',
                ],
            ]),
        ]);

        $this->artisan('ingestion:scrape', ['ticker' => 'NVDA'])
            ->expectsOutputToContain('skipped 2 malformed rows')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_bars', 1);
    }
}
