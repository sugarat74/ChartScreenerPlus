<?php

namespace App\Support;

use RuntimeException;

/**
 * Reader of the committed constituent datasets (`database/data/<slug>.csv`,
 * header `ticker,company,sector,industry,country,exchange`, UTF-8). Shared by
 * the S&P 500 seeder and the index seeders. Offline: never touches the network.
 */
final class ConstituentCsv
{
    /**
     * @return list<array{ticker: string, company: string, sector: string, industry: string, country: string, exchange: string}>
     */
    public static function read(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("{$label} dataset not found at {$path}.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the {$label} dataset at {$path}.");
        }

        try {
            $header = self::readHeader($handle, $path, $label);
            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;

                // A fully blank line parses as [null].
                if ($cells === [null]) {
                    continue;
                }

                $row = self::mapRow($cells, $header, $path, $line, $label);

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
            throw new RuntimeException("The {$label} dataset at {$path} contains no usable rows.");
        }

        return array_values($rows);
    }

    /**
     * @param  resource  $handle
     * @return array<string, int>
     */
    private static function readHeader($handle, string $path, string $label): array
    {
        $cells = fgetcsv($handle, null, ',', '"', '');

        if ($cells === false || $cells === [null]) {
            throw new RuntimeException("The {$label} dataset at {$path} has no header row.");
        }

        $header = [];

        foreach ($cells as $index => $cell) {
            // Strip a UTF-8 byte order mark if the file carries one.
            $name = strtolower(trim((string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF"));
            $header[$name] = $index;
        }

        foreach (['ticker', 'company', 'sector', 'industry', 'country', 'exchange'] as $column) {
            if (! array_key_exists($column, $header)) {
                throw new RuntimeException("The {$label} dataset at {$path} is missing the `{$column}` column.");
            }
        }

        return $header;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $header
     * @return array{ticker: string, company: string, sector: string, industry: string, country: string, exchange: string}|null
     */
    private static function mapRow(array $cells, array $header, string $path, int $line, string $label): ?array
    {
        $value = fn (string $column): string => trim((string) ($cells[$header[$column]] ?? ''));

        $ticker = strtoupper($value('ticker'));
        $company = $value('company');

        if ($ticker === '') {
            return null;
        }

        if ($company === '') {
            throw new RuntimeException(
                "The {$label} dataset at {$path} has no company for ticker `{$ticker}` (line {$line})."
            );
        }

        return [
            'ticker' => $ticker,
            'company' => $company,
            'sector' => $value('sector'),
            'industry' => $value('industry'),
            'country' => $value('country'),
            'exchange' => $value('exchange'),
        ];
    }
}
