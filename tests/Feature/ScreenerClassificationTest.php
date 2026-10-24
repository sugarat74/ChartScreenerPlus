<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Universe;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_candidates_carry_stored_classification_and_unknowns_without_etfs(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $known = Instrument::factory()->create([
            'ticker' => 'KNOWN', 'sector' => 'Information Technology',
            'industry' => 'Semiconductors', 'country' => 'US',
        ]);
        $unknown = Instrument::factory()->create([
            'ticker' => 'UNKNOWN', 'sector' => null, 'industry' => null, 'country' => null,
        ]);
        $etf = Instrument::factory()->create([
            'ticker' => 'XLK', 'kind' => Instrument::KIND_ETF,
            'sector' => 'Information Technology', 'industry' => 'Semiconductors', 'country' => 'US',
        ]);
        $universe->instruments()->attach([$known->id, $unknown->id, $etf->id]);
        foreach ([$known, $unknown, $etf] as $instrument) {
            DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-23']);
            IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-23']);
        }

        $this->artisan('screener:refresh')->assertExitCode(0);
        $response = $this->getJson('/api/screener?universe=sp500')->assertOk()->json('candidates');
        $this->assertCount(2, $response);
        $byTicker = collect($response)->keyBy('ticker');
        $this->assertSame('Information Technology', $byTicker['KNOWN']['sector']);
        $this->assertSame('Semiconductors', $byTicker['KNOWN']['industry']);
        $this->assertSame('US', $byTicker['KNOWN']['country']);
        $this->assertNull($byTicker['UNKNOWN']['sector']);
        $this->assertNull($byTicker['UNKNOWN']['industry']);
        $this->assertNull($byTicker['UNKNOWN']['country']);
        $this->assertFalse($byTicker->has('XLK'));
    }

    public function test_classification_migration_backfills_only_new_fields_of_existing_instruments(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $nvda = Instrument::query()->where('ticker', 'NVDA')->sole();
        $nvda->update(['company' => 'Admin Custom', 'active' => false]);
        $universe = Universe::query()->where('slug', 'sp500')->sole();
        $universe->instruments()->detach($nvda->id);

        $migration = require base_path('database/migrations/2026_10_27_000001_add_classification_to_instruments_table.php');
        $migration->down();
        $migration->up();

        $nvda->refresh();
        $this->assertSame('Semiconductors', $nvda->industry);
        $this->assertSame('US', $nvda->country);
        $this->assertSame('Admin Custom', $nvda->company);
        $this->assertFalse($nvda->active);
        $this->assertFalse($universe->instruments()->whereKey($nvda->id)->exists());
    }
}
