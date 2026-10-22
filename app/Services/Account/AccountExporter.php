<?php

namespace App\Services\Account;

use App\Models\AiCompanyCardGeneration;
use App\Models\AiScreenerQuery;
use App\Models\AlertEmailDigest;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\LoginEvent;
use App\Models\SavedScreener;
use App\Models\User;
use App\Services\Admin\SessionDirectory;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Builds the self-service copy of a Registered User's personal data
 * (account-self-service, GDPR arts. 15 and 20).
 *
 * Every section starts from the given User (its relations, or its own
 * `sessions` rows), so it is scoped by construction. It never contains the
 * password hash, the remember token, session ids or payloads, or other
 * accounts' data. A new user-owned table must be added here and to
 * `AccountDeleter` (`AccountSelfServiceTest` fails otherwise).
 */
class AccountExporter
{
    public const FORMAT = 'chartiko-account-export-v1';

    public function __construct(private readonly SessionDirectory $sessions) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        return [
            'format' => self::FORMAT,
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => self::date($user->created_at),
                'updated_at' => self::date($user->updated_at),
                'email_verified_at' => self::date($user->email_verified_at),
                'alert_email_opt_in_at' => self::date($user->alert_email_opt_in_at),
                'preferred_locale' => $user->preferred_locale,
            ],
            'watchlist' => $user->watchlist()
                ->orderBy('watchlist_items.created_at')
                ->orderBy('instruments.ticker')
                ->get()
                ->map(fn (Instrument $instrument): array => [
                    'ticker' => $instrument->ticker,
                    'name' => $instrument->company,
                    'added_at' => self::date($instrument->pivot?->created_at),
                ])
                ->values()
                ->all(),
            'saved_screeners' => $user->savedScreeners()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (SavedScreener $screener): array => [
                    'name' => $screener->name,
                    'criteria' => $screener->filters,
                    'created_at' => self::date($screener->created_at),
                ])
                ->values()
                ->all(),
            'alerts' => $user->alerts()
                ->with('savedScreener:id,name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (Alert $alert): array => [
                    'kind' => $alert->kind,
                    'saved_screener' => $alert->savedScreener?->name,
                    'signal_types' => $alert->signal_types,
                    'pattern_types' => $alert->pattern_types,
                    'pattern_events' => $alert->pattern_events,
                    'active' => $alert->active,
                    'last_evaluated_as_of' => $alert->last_evaluated_as_of?->toDateString(),
                    'last_state' => $alert->last_state,
                    'created_at' => self::date($alert->created_at),
                    'updated_at' => self::date($alert->updated_at),
                ])
                ->values()
                ->all(),
            'notifications' => $user->notifications()
                ->reorder()
                ->orderBy('created_at')
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'read_at' => self::date($notification->read_at),
                    'created_at' => self::date($notification->created_at),
                ])
                ->values()
                ->all(),
            'sign_in_activity' => $user->loginEvents()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (LoginEvent $event): array => [
                    'event' => $event->event,
                    'ip_address' => $event->ip_address,
                    'user_agent' => $event->user_agent,
                    'created_at' => self::date($event->created_at),
                ])
                ->values()
                ->all(),
            'active_sessions' => $this->sessions->table()
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get(['ip_address', 'user_agent', 'last_activity'])
                ->map(fn (object $row): array => [
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'last_activity_at' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'ai_card_requests' => $user->companyCardGenerations()
                ->with('instrument:id,ticker')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (AiCompanyCardGeneration $generation): array => [
                    'ticker' => $generation->instrument?->ticker,
                    'locale' => $generation->locale,
                    'created_at' => self::date($generation->created_at),
                ])
                ->values()
                ->all(),
            // Metadata only: the query text is never stored, so it cannot be exported.
            'ai_screener_requests' => $user->screenerQueries()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (AiScreenerQuery $query): array => [
                    'locale' => $query->locale,
                    'query_length' => $query->query_length,
                    'outcome' => $query->outcome,
                    'unsupported_count' => $query->unsupported_count,
                    'created_at' => self::date($query->created_at),
                ])
                ->values()
                ->all(),
            // alerts-email-digest ledger: which daily emails were queued and sent.
            'alert_emails' => $user->alertEmailDigests()
                ->orderBy('as_of')
                ->get()
                ->map(fn (AlertEmailDigest $digest): array => [
                    'as_of' => $digest->as_of->toDateString(),
                    'items' => $digest->items,
                    'sent_at' => self::date($digest->sent_at),
                ])
                ->values()
                ->all(),
        ];
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }
}
