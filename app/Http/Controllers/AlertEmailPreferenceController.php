<?php

namespace App\Http\Controllers;

use App\Services\Alerts\AlertEmailDigestAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The session user's daily alert email preference (alerts-email-digest).
 *
 * Opt-in is an explicit action (default off) and records its time and the
 * page language, which becomes the email language. While the feature is
 * unavailable the preference can be read and turned off, never on (409).
 */
class AlertEmailPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->payload($request);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['opt_in' => ['required', 'boolean']]);
        $user = $request->user();

        if ($validated['opt_in']) {
            if (! AlertEmailDigestAvailability::available()) {
                return response()->json(['message' => __('messages.alerts.email_unavailable')], 409);
            }
            $user->forceFill([
                'alert_email_opt_in_at' => $user->alert_email_opt_in_at ?? now(),
                'preferred_locale' => App::getLocale(),
            ])->save();
        } else {
            $user->forceFill(['alert_email_opt_in_at' => null])->save();
        }

        return $this->payload($request);
    }

    private function payload(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'available' => AlertEmailDigestAvailability::available(),
            'opted_in' => $user->wantsAlertEmails(),
            'opted_in_at' => $user->alert_email_opt_in_at?->toIso8601String(),
            'locale' => $user->preferred_locale,
        ]);
    }
}
