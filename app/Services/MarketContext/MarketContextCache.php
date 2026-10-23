<?php

namespace App\Services\MarketContext;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache of the Market Context payload and history (docs/specs/market-context-ui.md,
 * C5). Every key carries a version token; `bump()` stores a new token, so
 * everything cached before it is never read again (it expires with its TTL).
 * `market-context:compute`, `macro:refresh` and `market-regime:compute` bump
 * it when they finish ({@see MarketContextRunLog::finished()}).
 *
 * The latest payload key also carries the day it was built for, so the stale
 * flags are recomputed every day even without a run. Nothing cached here holds
 * user data: every allowed caller shares the same entries.
 */
final class MarketContextCache
{
    public const VERSION_KEY = 'market-context:version';

    /** The current version token, created on first read. */
    public static function version(): string
    {
        $version = Cache::get(self::VERSION_KEY);

        if (! is_string($version) || $version === '') {
            $version = (string) Str::uuid();
            Cache::forever(self::VERSION_KEY, $version);
        }

        return $version;
    }

    /** Invalidate every cached payload and history. */
    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function latest(string $asOf, Closure $build): array
    {
        return Cache::remember(self::key("latest:{$asOf}"), self::ttl(), $build);
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function history(string $metric, string $range, Closure $build): array
    {
        return Cache::remember(self::key("history:{$metric}:{$range}"), self::ttl(), $build);
    }

    /**
     * The sector map and regime label shared by every stock's
     * `GET /api/instruments/{ticker}/market-context` (market-context-patterns, C3).
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function instrumentContext(string $asOf, Closure $build): array
    {
        return Cache::remember(self::key("instrument-context:{$asOf}"), self::ttl(), $build);
    }

    private static function key(string $suffix): string
    {
        return 'market-context:'.self::version().':'.$suffix;
    }

    private static function ttl(): int
    {
        return max(1, (int) config('market_context.cache_ttl_seconds', 86400));
    }
}
