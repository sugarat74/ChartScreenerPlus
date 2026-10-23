<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Email verification (docs/specs/auth-email-verification.md, D5).
 *
 * `verify` is the POST the SPA page `/verificar-correo/...` makes with the
 * signed link of the email: the signature is the credential, so it needs no
 * session, and it never signs anyone in. A GET never verifies anything (mail
 * link scanners). `resend` mails a fresh link to the session user.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, int $user, string $hash): JsonResponse
    {
        // Relative signature over the path and the query (only `expires`; any
        // extra parameter breaks it). Expiry is checked after the account
        // state, so an old link of a verified account says so instead.
        if (! URL::hasCorrectSignature($request, false)) {
            return $this->error('invalid_link', 403);
        }

        $account = User::query()->find($user);
        if ($account === null || ! hash_equals(sha1($account->getEmailForVerification()), $hash)) {
            return $this->error('invalid_link', 403);
        }

        if ($account->hasVerifiedEmail()) {
            return response()->json(['status' => 'already_verified']);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->error('link_expired', 403);
        }

        if ($account->markEmailAsVerified()) {
            event(new Verified($account));
        }

        return response()->json(['status' => 'verified']);
    }

    public function resend(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, __('messages.stateful_session_required'));

        if (! EmailVerification::required()) {
            return $this->error('unavailable', 409);
        }

        /** @var User $user */
        $user = $request->user();
        if (! EmailVerification::pending($user)) {
            return response()->json(['status' => 'already_verified']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['status' => 'sent'], 202);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json([
            'code' => 'email_verification.'.$code,
            'message' => __('messages.email_verification.'.$code),
        ], $status);
    }
}
