<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\ScreenerRow;
use App\Models\Universe;
use Database\Seeders\MarketContextUniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `MarketContextUniverseSeeder` (docs/specs/market-context-sectors.md, Domain
 * Rules 1-2): offline, idempotent, 12 ETFs whose sectors map one-to-one onto
 * the GICS sectors of the committed S&P 500 dataset.
 */
class MarketContextUniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function datasetSectors(): array
    {
        $handle = fopen(base_path('database/data/sp500.csv'), 'rb');
        $header = array_map(fn (?string $cell): string => strtolower(trim((string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, null, ',', '"', ''));
        $column = array_search('sector', $header, true);
        $sectors = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells !== [null] && trim((string) ($cells[$column] ?? '')) !== '') {
                $sectors[trim((string) $cells[$column])] = true;
            }
        }
        fclose($handle);

        $sectors = array_keys($sectors);
        sort($sectors);

        return $sectors;
    }

    public function test_it_seeds_the_twelve_etfs_into_their_own_universe(): void
    {
        $this->seed(MarketContextUniverseSeeder::class);

        $universe = Universe::query()->where('slug', config('market_context.universe'))->sole();
        $this->assertSame('market-context', $universe->slug);
        $this->assertSame('Market Context', $universe->name);

        $etfs = $universe->instruments()->orderBy('ticker')->get();
        $this->assertSame(
            ['SPY', 'XLB', 'XLC', 'XLE', 'XLF', 'XLI', 'XLK', 'XLP', 'XLRE', 'XLU', 'XLV', 'XLY'],
            $etfs->pluck('ticker')->all(),
        );
        $this->assertSame([Instrument::KIND_ETF], $etfs->pluck('kind')->unique()->values()->all());
        $this->assertSame(['NYSE Arca'], $etfs->pluck('exchange')->unique()->values()->all());
        $this->assertTrue($etfs->every(fn (Instrument $etf): bool => $etf->active && $etf->isEtf()));

        // SPY is the benchmark (no sector); the 11 sector ETFs cover each
        // dataset sector exactly once, with the exact GICS strings.
        $this->assertNull($etfs->firstWhere('ticker', 'SPY')->sector);
        $this->assertSame('Information Technology', $etfs->firstWhere('ticker', 'XLK')->sector);
        $sectors = $etfs->where('ticker', '!=', 'SPY')->pluck('sector')->all();
        $this->assertCount(11, $sectors);
        $this->assertSame(count($sectors), count(array_unique($sectors)));
        sort($sectors);
        $this->assertSame($this->datasetSectors(), $sectors);
    }

    public function test_rerunning_is_idempotent_and_add_only(): void
    {
        $this->seed(MarketContextUniverseSeeder::class);
        $universe = Universe::query()->where('slug', 'market-context')->sole();
        $extra = Instrument::factory()->etf()->create(['ticker' => 'XLX']);
        $universe->instruments()->attach($extra->id);

        $this->seed(MarketContextUniverseSeeder::class);

        $this->assertSame(1, Universe::query()->where('slug', 'market-context')->count());
        $this->assertSame(13, Instrument::query()->count());
        $this->assertSame(13, $universe->instruments()->count());
        $this->assertDatabaseCount('instrument_universe', 13);
    }

    public function test_seeding_turns_an_existing_ticker_into_an_etf_and_drops_its_screener_row(): void
    {
        $xlk = Instrument::factory()->create(['ticker' => 'XLK']);
        DailyBar::factory()->create(['instrument_id' => $xlk->id, 'date' => '2026-10-01']);
        IndicatorSnapshot::factory()->create(['instrument_id' => $xlk->id, 'date' => '2026-10-01']);
        $this->refreshScreenerRows();
        $this->assertSame(1, ScreenerRow::query()->count());

        $this->seed(MarketContextUniverseSeeder::class);

        $this->assertTrue($xlk->fresh()->isEtf());
        $this->assertSame(0, ScreenerRow::query()->count());
    }
}
