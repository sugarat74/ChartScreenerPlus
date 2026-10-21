<?php

namespace App\Jobs;

use App\Mail\AlertDigest;
use App\Models\AlertEmailDigest;
use App\Services\Alerts\AlertDigestContent;
use App\Services\Alerts\AlertEmailDigestAvailability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Sends one ledger row's daily alert email (alerts-email-digest).
 *
 * Runs on the queue, so a mail failure never touches the data pipeline. It
 * retries 3 times; a row is marked `sent_at` only after the transport accepted
 * the message, and a sent row is never sent again. The user's opt-in and the
 * feature availability are re-checked at send time, so an unsubscribe between
 * queueing and sending is honoured.
 */
class SendAlertDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Localizable, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $digestId) {}

    public function handle(): void
    {
        $ledger = AlertEmailDigest::query()->with('user')->find($this->digestId);
        $user = $ledger?->user;

        if ($ledger === null || $user === null || $ledger->sent_at !== null) {
            return;
        }

        if (! AlertEmailDigestAvailability::available() || ! $user->wantsAlertEmails()) {
            return;
        }

        $locale = in_array($user->preferred_locale, config('locales.supported'), true)
            ? $user->preferred_locale
            : (string) config('locales.default');

        $digest = $this->withLocale($locale, fn (): AlertDigestContent => AlertDigestContent::for($user, $ledger->as_of->toDateString()));

        if ($digest->sections === []) {
            return;
        }

        Mail::to($user->email)->locale($locale)->send(new AlertDigest($user, $digest));

        $ledger->forceFill(['sent_at' => now()])->save();
    }

    public function failed(?Throwable $exception): void
    {
        // No address or content in the log: the ledger id is enough to trace it.
        Log::warning('Alert email digest failed after retries.', [
            'alert_email_digest_id' => $this->digestId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
