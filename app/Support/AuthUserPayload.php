<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The `user` object of the auth responses (`POST /api/register`,
 * `POST /api/login`, `GET /api/user`, `PATCH /api/account`): the model's
 * visible attributes plus the capabilities the SPA uses to show or hide
 * entitled surfaces (docs/specs/market-context-patterns.md, C1) and whether
 * the account still has to confirm its email (auth-email-verification). The
 * capability only drives UI; the API checks the same ability itself.
 */
final class AuthUserPayload
{
    /** @return array<string, mixed> */
    public static function make(User $user): array
    {
        return $user->toArray() + [
            // auth-email-verification: while true the SPA shows only the
            // "confirm your email" screen; the API enforces it itself.
            'email_verification_pending' => EmailVerification::pending($user),
            'capabilities' => [
                'market_context' => Gate::forUser($user)->allows('view-market-context'),
            ],
        ];
    }
}
