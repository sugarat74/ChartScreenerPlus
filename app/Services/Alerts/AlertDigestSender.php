<?php

namespace App\Services\Alerts;

use App\Jobs\SendAlertDigest;
use App\Models\AlertEmailDigest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Queues the daily alert emails for one as-of date (alerts-email-digest).
 *
 * Called by `alerts:evaluate` after the evaluation. Only opted-in users with
 * at least one notification for that as-of get a ledger row and a queued
 * job; the unique `(user_id, as_of)` ledger makes a re-run queue nothing.
 * Nothing happens while the feature is unavailable.
 */
final class AlertDigestSender
{
    /**
     * @return int Digests queued now.
     */
    public function queue(string $asOf): int
    {
        if (! AlertEmailDigestAvailability::available()) {
            return 0;
        }

        $queued = 0;

        User::query()
            ->whereNotNull('alert_email_opt_in_at')
            ->whereDoesntHave('alertEmailDigests', fn ($query) => $query->whereDate('as_of', $asOf))
            ->orderBy('id')
            ->each(function (User $user) use ($asOf, &$queued): void {
                $notifications = AlertDigestContent::notificationsFor($user, $asOf);
                if ($notifications === []) {
                    return;
                }

                $items = array_sum(array_map(
                    fn ($notification): int => count($notification->data['items'] ?? []) + (int) ($notification->data['more'] ?? 0),
                    $notifications,
                ));

                try {
                    $ledger = AlertEmailDigest::query()->create([
                        'user_id' => $user->id,
                        'as_of' => $asOf,
                        'items' => $items,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return; // A concurrent run already queued it.
                }

                SendAlertDigest::dispatch($ledger->id);
                $queued++;
            });

        return $queued;
    }
}
