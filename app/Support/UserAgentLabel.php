<?php

namespace App\Support;

/**
 * Coarse browser/OS label for a user agent, for the Admin session list.
 * Deliberately small: unknown agents yield nulls and the SPA shows a
 * translated "unknown" label. The raw user agent is never needed in the UI.
 */
final class UserAgentLabel
{
    /** Order matters: Edge and Opera also contain "Chrome", Chrome contains "Safari". */
    private const BROWSERS = [
        'Edge' => '/Edg(e|A|iOS)?\//',
        'Opera' => '/(OPR|Opera)\//',
        'Firefox' => '/(Firefox|FxiOS)\//',
        'Chrome' => '/(Chrome|CriOS)\//',
        'Safari' => '/Version\/[\d.]+.*Safari\//',
    ];

    private const SYSTEMS = [
        'iOS' => '/(iPhone|iPad|iPod)/',
        'Android' => '/Android/',
        'Windows' => '/Windows/',
        'macOS' => '/Mac OS X|Macintosh/',
        'Linux' => '/Linux/',
    ];

    /** @return array{browser: string|null, os: string|null} */
    public static function describe(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return [
            'browser' => self::first(self::BROWSERS, $agent),
            'os' => self::first(self::SYSTEMS, $agent),
        ];
    }

    /** @param array<string, string> $patterns */
    private static function first(array $patterns, string $agent): ?string
    {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $label;
            }
        }

        return null;
    }
}
