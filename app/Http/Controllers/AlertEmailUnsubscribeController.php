<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Alerts\AlertDigestContent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Traits\Localizable;

/**
 * Unsubscribe from the daily alert email without a session (alerts-email-digest).
 *
 * Both routes require a valid signature (`signed:relative`, 403 otherwise)
 * over the user id. GET is side-effect free: link scanners and previewers
 * fetch links on their own, so it only shows a page with a button. Only POST
 * clears the opt-in: the button's form (answered with the confirmation page)
 * or the RFC 8058 one-click request from the `List-Unsubscribe-Post` header
 * (answered with 204). Both are idempotent, and an unknown user with a valid
 * signature (a deleted account) gets the same answer. CSRF is exempt for
 * this path (bootstrap/app.php): the signature protects it.
 */
class AlertEmailUnsubscribeController extends Controller
{
    use Localizable;

    public function show(Request $request, int $user): Response
    {
        return $this->page($this->locale(User::query()->find($user)), confirmed: false, action: $request->getRequestUri());
    }

    public function unsubscribe(Request $request, int $user): Response
    {
        $account = User::query()->find($user);
        $account?->forceFill(['alert_email_opt_in_at' => null])->save();

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 204);
        }

        return $this->page($this->locale($account), confirmed: true, action: null);
    }

    private function page(string $locale, bool $confirmed, ?string $action): Response
    {
        return $this->withLocale($locale, fn () => response()
            ->view('alert-email-unsubscribed', [
                'confirmed' => $confirmed,
                'action' => $action,
                'siteUrl' => AlertDigestContent::siteUrl('/'),
            ])
            ->header('Content-Language', $locale)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'no-store'));
    }

    private function locale(?User $user): string
    {
        $preferred = $user?->preferred_locale;

        return in_array($preferred, config('locales.supported'), true)
            ? $preferred
            : App::getLocale();
    }
}
