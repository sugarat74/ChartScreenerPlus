<?php

namespace Database\Seeders;

use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the S&P 500 universe from the committed dataset.
 *
 * Dataset: `database/data/sp500.csv` with the header
 * `ticker,company,sector,exchange` (UTF-8, one row per constituent).
 *
 * Source: Wikipedia, "List of S&P 500 companies"
 * (https://en.wikipedia.org/wiki/List_of_S%26P_500_companies).
 * Generated once at implementation time (2026-09-29): 503 constituents taken
 * from the component table's Symbol / Security / GICS Sector columns. The
 * `exchange` value is derived from each symbol's listing link (NYSE / NASDAQ /
 * CBOE) and is left empty when the source does not identify an exchange.
 *
 * The seeder is offline (reads only the committed CSV, never the network) and
 * idempotent: the universe is upserted by slug, instruments by unique ticker,
 * and membership is attached with `syncWithoutDetaching` so re-running adds no
 * duplicates and never removes an instrument that already belongs to the
 * universe.
 */
class Sp500UniverseSeeder extends Seeder
{
    public const UNIVERSE_SLUG = 'sp500';

    public const UNIVERSE_NAME = 'S&P 500';

    public const DATASET_PATH = 'database/data/sp500.csv';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = $this->readDataset();

        DB::transaction(function () use ($rows): void {
            $universe = Universe::query()->updateOrCreate(
                ['slug' => self::UNIVERSE_SLUG],
                ['name' => self::UNIVERSE_NAME],
            );

            $instrumentIds = [];

            foreach ($rows as $row) {
                $instrument = Instrument::query()->updateOrCreate(
                    ['ticker' => $row['ticker']],
                    [
                        'company' => $row['company'],
                        'sector' => $row['sector'] === '' ? null : $row['sector'],
                        'exchange' => $row['exchange'] === '' ? null : $row['exchange'],
                        'active' => true,
                    ],
                );

                $instrumentIds[] = $instrument->getKey();
            }

            // Add-only: keep existing memberships, attach any that are missing.
            $universe->instruments()->syncWithoutDetaching($instrumentIds);
        });
    }

    /**
     * Read the committed constituent dataset.
     *
     * @return list<array{ticker: string, company: string, sector: string, exchange: string}>
     */
    private function readDataset(): array
    {
        $path = base_path(self::DATASET_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("S&P 500 dataset not found at {$path}.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the S&P 500 dataset at {$path}.");
        }

        try {
            $header = $this->readHeader($handle, $path);
            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;

                // A fully blank line parses as [null].
                if ($cells === [null]) {
                    continue;
                }

                $row = $this->mapRow($cells, $header, $path, $line);

                if ($row === null) {
                    continue;
                }

                // Deduplicate by ticker, keeping the first occurrence.
                $rows[$row['ticker']] ??= $row;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException("The S&P 500 dataset at {$path} contains no usable rows.");
        }

        return array_values($rows);
    }

    /**
     * Read and normalize the CSV header.
     *
     * @param  resource  $handle
     * @return array<string, int>
     */
    private function readHeader($handle, string $path): array
    {
        $cells = fgetcsv($handle, null, ',', '"', '');

        if ($cells === false || $cells === [null]) {
            throw new RuntimeException("The S&P 500 dataset at {$path} has no header row.");
        }

        $header = [];

        foreach ($cells as $index => $cell) {
            // Strip a UTF-8 byte order mark if the file carries one.
            $name = strtolower(trim((string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF"));
            $header[$name] = $index;
        }

        foreach (['ticker', 'company', 'sector', 'exchange'] as $column) {
            if (! array_key_exists($column, $header)) {
                throw new RuntimeException(
                    "The S&P 500 dataset at {$path} is missing the `{$column}` column."
                );
            }
        }

        return $header;
    }

    /**
     * Map one CSV row to a normalized dataset row.
     *
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $header
     * @return array{ticker: string, company: string, sector: string, exchange: string}|null
     */
    private function mapRow(array $cells, array $header, string $path, int $line): ?array
    {
        $value = fn (string $column): string => trim((string) ($cells[$header[$column]] ?? ''));

        $ticker = strtoupper($value('ticker'));
        $company = $value('company');

        if ($ticker === '') {
            return null;
        }

        if ($company === '') {
            throw new RuntimeException(
                "The S&P 500 dataset at {$path} has no company for ticker `{$ticker}` (line {$line})."
            );
        }

        return [
            'ticker' => $ticker,
            'company' => $company,
            'sector' => $value('sector'),
            'exchange' => $value('exchange'),
        ];
    }
}
