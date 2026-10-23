<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmailAddress;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Queues the confirmation email (docs/specs/auth-email-verification.md, D6)
 * in the locale of the current request. A mail or queue failure never fails
 * registration or resend; the log keeps the user id and the exception class,
 * never the address.
 */
final class EmailVerificationMailer
{
    public function send(User $user): void
    {
        try {
            Mail::to($user->email)
                ->locale(app()->getLocale())
                ->queue(new VerifyEmailAddress(EmailVerification::link($user), $user->getKey()));
        } catch (Throwable $exception) {
            Log::warning('Verification email could not be queued.', [
                'user_id' => $user->getKey(),
                'error' => $exception::class,
            ]);
        }
    }
}
