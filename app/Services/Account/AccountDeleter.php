<?php

namespace App\Services\Account;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Admin\SessionDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a Registered User's own account and everything it owns
 * (account-self-service, GDPR art. 17), in one transaction so a failure never
 * leaves a half-deleted account.
 *
 * Order: audit row -> sessions (no FK) -> the user row. Deleting the user
 * removes notifications through `User::booted()`, cascades the Watchlist,
 * Saved Screeners and Alerts by FK, and nulls `login_events.user_id`,
 * `ai_company_card_generations.user_id` and `ai_screener_queries.user_id`
 * (shared logs that keep no account link). A new user-owned table must be handled here and in `AccountExporter`.
 * Admins are refused by the controller before reaching this service.
 */
class AccountDeleter
{
    public function __construct(private readonly SessionDirectory $sessions) {}

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            LoginEvent::query()->create([
                'event' => LoginEvent::EVENT_ACCOUNT_DELETED,
                'user_id' => null,
                'actor_id' => null,
                'email' => mb_substr(mb_strtolower(trim($user->email)), 0, 255),
                'ip_address' => null,
                'user_agent' => null,
            ]);

            $this->sessions->deleteForUser($user->id, null);

            // Defensive: no tokens or reset rows are issued today, but they
            // would otherwise outlive the account (morph / email keyed, no FK).
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->delete();
        });
    }
}
