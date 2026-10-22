<?php

namespace App\Services\Alerts;

use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The content of one daily alert email (alerts-email-digest): the user's
 * notifications for one as-of date, grouped by alert, as plain localized
 * strings. At most `alerts.email_digest.max_items` instruments are listed;
 * the rest (and each notification's own omitted `more`) is counted.
 *
 * Built in the locale active when it runs (the job sets the user's locale).
 */
final class AlertDigestContent
{
    /**
     * @param  list<array{title: string, items: list<array{ticker: string, name: string|null, label: string, url: string}>, more: int}>  $sections
     */
    private function __construct(
        public readonly string $asOf,
        public readonly string $date,
        public readonly array $sections,
        public readonly int $total,
    ) {}

    /**
     * Notifications created by `alerts:evaluate` for `$asOf`. Read in PHP
     * rather than with a JSON query so SQLite and PostgreSQL behave alike;
     * the window is bounded to recent rows.
     *
     * @return list<DatabaseNotification>
     */
    public static function notificationsFor(User $user, string $asOf): array
    {
        // Same order as the evaluation (alerts by id); ids are UUIDs, so they
        // cannot break ties between notifications created in the same second.
        return $user->notifications()
            ->where('type', AlertTriggered::class)
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['as_of'] ?? null) === $asOf)
            ->sortBy([
                fn (DatabaseNotification $a, DatabaseNotification $b): int => $a->created_at <=> $b->created_at,
                fn (DatabaseNotification $a, DatabaseNotification $b): int => (int) ($a->data['alert_id'] ?? 0) <=> (int) ($b->data['alert_id'] ?? 0),
            ])
            ->values()
            ->all();
    }

    public static function for(User $user, string $asOf): self
    {
        $cap = max(1, (int) config('alerts.email_digest.max_items'));
        $sections = [];
        $listed = 0;
        $total = 0;

        foreach (self::notificationsFor($user, $asOf) as $notification) {
            $data = $notification->data;
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $omitted = max(0, (int) ($data['more'] ?? 0));
            $total += count($items) + $omitted;

            $shown = [];
            foreach ($items as $item) {
                if ($listed >= $cap || ! is_array($item) || ! is_string($item['ticker'] ?? null)) {
                    $omitted++;

                    continue;
                }
                $shown[] = [
                    'ticker' => $item['ticker'],
                    'name' => is_string($item['name'] ?? null) ? $item['name'] : null,
                    'label' => self::reason($item),
                    'url' => self::siteUrl('/instruments/'.rawurlencode($item['ticker'])),
                ];
                $listed++;
            }

            $sections[] = [
                'title' => self::title($data),
                'items' => $shown,
                'more' => $omitted,
            ];
        }

        return new self(
            asOf: $asOf,
            date: Carbon::parse($asOf)->locale(app()->getLocale())->isoFormat('LL'),
            sections: $sections,
            total: $total,
        );
    }

    public static function siteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function title(array $data): string
    {
        return match ($data['kind'] ?? null) {
            'watchlist_signal' => __('alert_email.kind_watchlist'),
            'watchlist_pattern' => __('alert_email.kind_watchlist_pattern'),
            default => is_string($data['saved_screener_name'] ?? null) && $data['saved_screener_name'] !== ''
                ? __('alert_email.kind_screener', ['name' => $data['saved_screener_name']])
                : __('alert_email.kind_screener_unnamed'),
        };
    }

    /**
     * Same wording as the in-app inbox (NotificationsPage.tsx `reasonLabel`).
     *
     * @param  array<string, mixed>  $item
     */
    private static function reason(array $item): string
    {
        $reason = (string) ($item['reason'] ?? '');

        if ($reason === 'new_candidate') {
            return __('alert_email.reason_new_candidate');
        }

        if (str_starts_with($reason, 'pattern:')) {
            [$type, $event] = array_pad(explode(':', substr($reason, strlen('pattern:')), 2), 2, '');
            $parts = [self::label("alert_email.patterns.{$type}", $type)];
            $parts[] = self::label("alert_email.pattern_event.{$event}", $event);
            if ($event === 'appears' && ($item['status'] ?? null) === 'confirmed') {
                $parts[] = __('alert_email.pattern_already_confirmed');
            }
            if (is_int($item['breakout_level'] ?? null) || is_float($item['breakout_level'] ?? null)) {
                $parts[] = __('alert_email.breakout_level', ['price' => self::price((float) $item['breakout_level'])]);
            }

            return implode(' · ', array_filter($parts, fn (string $part): bool => $part !== ''));
        }

        return self::label("alert_email.signals.{$reason}", $reason);
    }

    /** A translation, or the raw value when the key is unknown. */
    private static function label(string $key, string $fallback): string
    {
        $text = __($key);

        return $text === $key ? $fallback : $text;
    }

    private static function price(float $value): string
    {
        return app()->getLocale() === 'es'
            ? number_format($value, 2, ',', '.')
            : number_format($value, 2, '.', ',');
    }
}
