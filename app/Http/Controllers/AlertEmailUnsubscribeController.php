<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Alerts\AlertDigestContent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Traits\Localizable;

/**
 * Unsubscribe from the daily alert email without a session (alerts-email-digest).
 *
 * Both routes require a valid signature (`signed` middleware, 403 otherwise)
 * over the user id. GET shows a confirmation page in the user's language;
 * POST is the RFC 8058 one-click endpoint mail clients call from the
 * `List-Unsubscribe-Post` header. Both are idempotent, and an unknown user
 * with a valid signature (a deleted account) gets the same answer.
 */
class AlertEmailUnsubscribeController extends Controller
{
    use Localizable;

    public function show(int $user): Response
    {
        $locale = $this->unsubscribe($user);

        return $this->withLocale($locale, fn () => response()
            ->view('alert-email-unsubscribed', ['siteUrl' => AlertDigestContent::siteUrl('/')])
            ->header('Content-Language', $locale)
            ->header('X-Robots-Tag', 'noindex'));
    }

    public function oneClick(int $user): Response
    {
        $this->unsubscribe($user);

        return response('', 204);
    }

    /** Clears the opt-in; returns the language for the answer. */
    private function unsubscribe(int $id): string
    {
        $user = User::query()->find($id);
        $user?->forceFill(['alert_email_opt_in_at' => null])->save();

        $preferred = $user?->preferred_locale;

        return in_array($preferred, config('locales.supported'), true)
            ? $preferred
            : App::getLocale();
    }
}
