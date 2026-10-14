<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notification for one Alert evaluation that found something new.
 *
 * Stored with Laravel's database channel only (no mail, no broadcast). The
 * payload is language-neutral data: the SPA renders the text in the user's
 * language. `items` is capped by `config('alerts.max_items')`; `more` counts
 * the omitted rest.
 */
class AlertTriggered extends Notification
{
    /**
     * Pattern alerts (`watchlist_pattern`) add `status` and `breakout_level`
     * to each item and use `reason` = `pattern:<type>:<appears|confirmed>`.
     *
     * @param  list<array{ticker: string, name: string|null, reason: string, status?: string, breakout_level?: float}>  $items
     */
    public function __construct(
        public readonly int $alertId,
        public readonly string $kind,
        public readonly string $asOf,
        public readonly ?int $savedScreenerId,
        public readonly ?string $savedScreenerName,
        public readonly array $items,
        public readonly int $more,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alertId,
            'kind' => $this->kind,
            'as_of' => $this->asOf,
            'saved_screener_id' => $this->savedScreenerId,
            'saved_screener_name' => $this->savedScreenerName,
            'items' => $this->items,
            'more' => $this->more,
        ];
    }
}
