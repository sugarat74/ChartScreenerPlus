<?php

namespace Database\Seeders;

use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Screener\ScreenerRowBuilder;
use App\Support\ConstituentCsv;
use App\Support\ListingMarket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base seeder of a Screener index universe (docs/specs/multi-market-universes.md,
 * D2, D3, D6, D7). A concrete seeder only names the universe and its dataset.
 *
 * Dataset: a committed, dated CSV `database/data/<slug>.csv` with the header
 * `ticker,company,sector,industry,country,exchange`; the seeder is offline.
 *
 * Unlike the add-only S&P 500 seeder it syncs membership to the snapshot
 * exactly: new members are attached, members no longer listed are detached,
 * and no Instrument or history is ever deleted. An Instrument left in no
 * Screener universe becomes inactive (it keeps its chart and Watchlist
 * entries and is no longer ingested); a re-added one becomes active again.
 *
 * A company that is already an S&P 500 Instrument (D3) only gains membership:
 * the S&P 500 dataset stays the source of truth for its fields. Every member
 * shares the universe's Listing Market and currency, and its ticker must carry
 * the market's suffix (`.MC` for XMAD, `.DE` for XETR, none for the US).
 * Idempotent: re-running changes nothing.
 */
abstract class IndexUniverseSeeder extends Seeder
{
    /** The universe slug, also the dataset file name. */
    abstract protected function slug(): string;

    /** The proper-noun display name ("Nasdaq-100"); not translated. */
    abstract protected function universeName(): string;

    /** One of the `ListingMarket` codes. */
    abstract protected function market(): string;

    /** The selector order (the S&P 500 is 0). */
    abstract protected function position(): int;

    /** The dataset path relative to the base path. */
    protected function datasetPath(): string
    {
        return 'database/data/'.$this->slug().'.csv';
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $market = $this->market();

        if (! ListingMarket::isValid($market)) {
            throw new RuntimeException("Unknown Listing Market `{$market}` for universe `{$this->slug()}`.");
        }

        $currency = ListingMarket::currency($market);
        $rows = ConstituentCsv::read(base_path($this->datasetPath()), $this->universeName());

        foreach ($rows as $row) {
            if (! ListingMarket::tickerMatches($market, $row['ticker'])) {
                throw new RuntimeException(
                    "Ticker `{$row['ticker']}` does not match the suffix rule of Listing Market {$market} in universe `{$this->slug()}`."
                );
            }
        }

        DB::transaction(function () use ($rows, $market, $currency): void {
            $universe = Universe::query()->updateOrCreate(
                ['slug' => $this->slug()],
                [
                    'name' => $this->universeName(),
                    'market' => $market,
                    'currency' => $currency,
                    'is_screener' => true,
                    'position' => $this->position(),
                ],
            );

            $sp500Ids = $this->sp500InstrumentIds();
            $instrumentIds = [];

            foreach ($rows as $row) {
                $existing = Instrument::query()->where('ticker', $row['ticker'])->first();

                if ($existing !== null && ($existing->listingMarket() !== $market || $existing->currency !== $currency)) {
                    throw new RuntimeException(
                        "Instrument `{$row['ticker']}` is listed in {$existing->listingMarket()}/{$existing->currency}, not {$market}/{$currency} of universe `{$this->slug()}`."
                    );
                }

                if ($existing !== null && in_array($existing->getKey(), $sp500Ids, true)) {
                    // D3: the S&P 500 dataset owns an overlapping Instrument.
                    $instrumentIds[] = $existing->getKey();

                    continue;
                }

                $instrument = Instrument::query()->updateOrCreate(
                    ['ticker' => $row['ticker']],
                    [
                        'company' => $row['company'],
                        'sector' => $row['sector'] === '' ? null : $row['sector'],
                        'industry' => $row['industry'] === '' ? null : $row['industry'],
                        'country' => $row['country'] === '' ? null : $row['country'],
                        'exchange' => $row['exchange'] === '' ? null : $row['exchange'],
                        'market' => $market,
                        'currency' => $currency,
                        'active' => true,
                    ],
                );

                $instrumentIds[] = $instrument->getKey();
            }

            // Exact sync: attach new members, detach the ones no longer listed.
            $changes = $universe->instruments()->sync($instrumentIds);

            $this->deactivateOrphans($changes['detached']);
        });

        // Company, sector, market, currency and active flags are copied into the Screener rows.
        app(ScreenerRowBuilder::class)->refresh();
    }

    /**
     * @return list<int>
     */
    private function sp500InstrumentIds(): array
    {
        $universe = Universe::query()->where('slug', Sp500UniverseSeeder::UNIVERSE_SLUG)->first();

        return $universe === null
            ? []
            : array_map('intval', $universe->instruments()->pluck('instruments.id')->all());
    }

    /**
     * Detached Instruments that remain in no Screener universe stop being
     * ingested (`active = false`); their bars, charts and Watchlist entries stay.
     *
     * @param  array<int, int|string>  $detached
     */
    private function deactivateOrphans(array $detached): void
    {
        if ($detached === []) {
            return;
        }

        Instrument::query()
            ->whereIn('id', $detached)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('instrument_universe')
                ->join('universes', 'universes.id', '=', 'instrument_universe.universe_id')
                ->whereColumn('instrument_universe.instrument_id', 'instruments.id')
                ->where('universes.is_screener', true))
            ->update(['active' => false]);
    }
}
