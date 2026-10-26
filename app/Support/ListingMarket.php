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

    /**
     * `suffix` is the ticker suffix that identifies the market (`null` for the
     * US, whose tickers carry none and never end in another market's suffix).
     *
     * @var array<string, array{timezone: string, close: string, currency: string, suffix: ?string}>
     */
    private const DEFINITIONS = [
        self::US => ['timezone' => 'America/New_York', 'close' => '16:00', 'currency' => 'USD', 'suffix' => null],
        self::XMAD => ['timezone' => 'Europe/Madrid', 'close' => '17:30', 'currency' => 'EUR', 'suffix' => '.MC'],
        self::XETR => ['timezone' => 'Europe/Berlin', 'close' => '17:30', 'currency' => 'EUR', 'suffix' => '.DE'],
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
     * Whether `$ticker` follows the suffix rule of `$market`: `.MC` is exactly
     * `XMAD`, `.DE` exactly `XETR`, and a US ticker ends in neither.
     */
    public static function tickerMatches(string $market, string $ticker): bool
    {
        if (! self::isValid($market)) {
            return false;
        }

        $ticker = strtoupper($ticker);

        foreach (self::DEFINITIONS as $code => $definition) {
            $suffix = $definition['suffix'];

            if ($suffix === null) {
                continue;
            }

            if (str_ends_with($ticker, $suffix) !== ($code === $market)) {
                return false;
            }
        }

        return true;
    }
}
