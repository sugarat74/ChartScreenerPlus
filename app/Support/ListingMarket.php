<?php

namespace App\Support;

/**
 * Listing Market (Mercado de cotización): the exchange group whose trading
 * calendar, session close, currency and data-source symbol rules apply to an
 * Instrument (docs/specs/multi-market-universes.md, D1/D2). A closed list.
 *
 * Deliberately not called "market", which would clash with Market Context.
 */
final class ListingMarket
{
    public const US = 'US';

    public const XMAD = 'XMAD';

    public const XETR = 'XETR';

    public const XPAR = 'XPAR';

    public const XLON = 'XLON';

    /**
     * `suffix` is the ticker suffix that identifies the market (`null` for the
     * US, whose tickers carry none and never end in another market's suffix).
     * `currency` is the quoted unit: `GBX` for London is pence as quoted by
     * the source, not ISO 4217 and never converted (universes-cac40-ftse100.md, P1).
     *
     * `extra_suffixes` lists documented exceptions: further suffixes that
     * also identify the market (`.AS` for XPAR, ArcelorMittal's Amsterdam line,
     * docs/specs/universes-cac40-ftse100.md, C3). Optional.
     *
     * @var array<string, array{timezone: string, open: string, close: string, currency: string, suffix: ?string, extra_suffixes?: list<string>}>
     */
    private const DEFINITIONS = [
        self::US => ['timezone' => 'America/New_York', 'open' => '09:30', 'close' => '16:00', 'currency' => 'USD', 'suffix' => null],
        self::XMAD => ['timezone' => 'Europe/Madrid', 'open' => '09:00', 'close' => '17:30', 'currency' => 'EUR', 'suffix' => '.MC'],
        self::XETR => ['timezone' => 'Europe/Berlin', 'open' => '09:00', 'close' => '17:30', 'currency' => 'EUR', 'suffix' => '.DE'],
        self::XPAR => ['timezone' => 'Europe/Paris', 'open' => '09:00', 'close' => '17:30', 'currency' => 'EUR', 'suffix' => '.PA', 'extra_suffixes' => ['.AS']],
        self::XLON => ['timezone' => 'Europe/London', 'open' => '08:00', 'close' => '16:30', 'currency' => 'GBX', 'suffix' => '.L'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::DEFINITIONS[$code]);
    }

    public static function timezone(string $code): string
    {
        return self::DEFINITIONS[$code]['timezone'];
    }

    public static function open(string $code): string
    {
        return self::DEFINITIONS[$code]['open'];
    }

    public static function close(string $code): string
    {
        return self::DEFINITIONS[$code]['close'];
    }

    public static function currency(string $code): string
    {
        return self::DEFINITIONS[$code]['currency'];
    }

    public static function suffix(string $code): ?string
    {
        return self::DEFINITIONS[$code]['suffix'];
    }

    /**
     * Every ticker suffix that identifies the market: the primary one plus the
     * documented exceptions (`.PA` and `.AS` for XPAR). Empty for the US.
     *
     * @return list<string>
     */
    public static function suffixes(string $code): array
    {
        $definition = self::DEFINITIONS[$code];

        if ($definition['suffix'] === null) {
            return [];
        }

        return [$definition['suffix'], ...($definition['extra_suffixes'] ?? [])];
    }

    /**
     * Whether `$ticker` follows the suffix rule of `$market`: `.MC` is exactly
     * `XMAD`, `.DE` exactly `XETR`, `.PA` (and the `.AS` exception) exactly
     * `XPAR`, `.L` exactly `XLON`, and a US ticker ends in none of them.
     */
    public static function tickerMatches(string $market, string $ticker): bool
    {
        if (! self::isValid($market)) {
            return false;
        }

        $ticker = strtoupper($ticker);

        foreach (array_keys(self::DEFINITIONS) as $code) {
            $suffixes = self::suffixes($code);

            if ($suffixes === []) {
                continue;
            }

            $endsWithOwn = false;
            foreach ($suffixes as $suffix) {
                if (str_ends_with($ticker, $suffix)) {
                    $endsWithOwn = true;
                    break;
                }
            }

            if ($endsWithOwn !== ($code === $market)) {
                return false;
            }
        }

        return true;
    }
}
