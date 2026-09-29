<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\Universe;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Sp500UniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_universe_and_its_instruments(): void
    {
        $this->seed(Sp500UniverseSeeder::class);

        $universe = Universe::query()->where('slug', 'sp500')->first();

        $this->assertNotNull($universe, 'The S&P 500 universe was not created.');
        $this->assertSame('S&P 500', $universe->name);

        $expected = $this->datasetRowCount();

        // Guard against a silently truncated/empty dataset.
        $this->assertGreaterThan(400, $expected, 'The committed dataset looks too small.');

        $this->assertSame($expected, Instrument::query()->count());
        $this->assertSame($expected, DB::table('instrument_universe')->count());
        $this->assertSame($expected, $universe->instruments()->count());

        // Spot-check a known constituent.
        $nvda = Instrument::query()->where('ticker', 'NVDA')->first();

        $this->assertNotNull($nvda, 'NVDA is missing from the seeded universe.');
        $this->assertSame('Nvidia', $nvda->company);
        $this->assertSame('Information Technology', $nvda->sector);
        $this->assertSame('NASDAQ', $nvda->exchange);
        $this->assertTrue($nvda->active);
        $this->assertTrue($nvda->universes()->whereKey($universe->getKey())->exists());
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seed(Sp500UniverseSeeder::class);

        $expected = $this->datasetRowCount();
        $this->assertSame($expected, Instrument::query()->count());

        // Run a second time: no duplicates, no extra pivots, no second universe.
        $this->seed(Sp500UniverseSeeder::class);

        $this->assertSame(1, Universe::query()->count());
        $this->assertSame($expected, Instrument::query()->count());
        $this->assertSame($expected, DB::table('instrument_universe')->count());
    }

    /**
     * Count the data rows in the committed dataset (header excluded), reading
     * the file dynamically so the test never hardcodes the constituent count.
     */
    private function datasetRowCount(): int
    {
        $path = base_path(Sp500UniverseSeeder::DATASET_PATH);

        $this->assertFileExists($path);

        $handle = fopen($path, 'rb');

        $this->assertNotFalse($handle, "Unable to open the dataset at {$path}.");

        $count = 0;

        try {
            $header = fgetcsv($handle, null, ',', '"', '');

            $this->assertSame(
                ['ticker', 'company', 'sector', 'exchange'],
                array_map(static fn ($cell) => strtolower(trim((string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header),
            );

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }
}
