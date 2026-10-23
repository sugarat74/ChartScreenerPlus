<?php

namespace App\Http\Controllers;

use App\Services\Alerts\AlertEmailDigestAvailability;
use Illuminate\Http\JsonResponse;

/**
 * Public facts the legal pages render (docs/specs/legal-compliance-eu.md,
 * docs/specs/legal-privacy-publication.md).
 *
 * Two gates, one per page that identifies the owner:
 * - the privacy policy is published once the owner's name and a valid
 *   contact email are configured;
 * - the legal notice additionally needs the tax id and the address.
 *
 * No owner field is serialized unless the page that needs it is published:
 * `owner` is null while the privacy policy is withheld, and `tax_id`,
 * `address` and `registry` stay null while the legal notice is withheld.
 * Retention figures come from the same configuration the application enforces.
 */
class LegalController extends Controller
{
    private const OWNER_FIELDS = ['name', 'tax_id', 'address', 'email', 'registry'];

    public function show(): JsonResponse
    {
        $values = $this->ownerValues();

        $privacyPublished = $values['name'] !== null
            && $values['email'] !== null
            && filter_var($values['email'], FILTER_VALIDATE_EMAIL) !== false;
        $noticePublished = $privacyPublished
            && $values['tax_id'] !== null
            && $values['address'] !== null;

        return response()->json([
            'privacy_published' => $privacyPublished,
            'notice_published' => $noticePublished,
            'owner' => $privacyPublished ? [
                'name' => $values['name'],
                'email' => $values['email'],
                'tax_id' => $noticePublished ? $values['tax_id'] : null,
                'address' => $noticePublished ? $values['address'] : null,
                'registry' => $noticePublished ? $values['registry'] : null,
            ] : null,
            'updated_at' => config('legal.updated_at'),
            'retention' => [
                'sign_in_activity_days' => (int) config('admin.activity_retention_days'),
                'session_minutes' => (int) config('session.lifetime'),
                'backup_days' => (int) config('legal.backup_retention_days'),
                'server_log_days' => (int) config('legal.server_log_retention_days'),
                'notification_days' => (int) config('alerts.notification_retention_days'),
                'ai_card_log_days' => (int) config('ai_card.log_retention_days'),
                'ai_screener_log_days' => (int) config('ai_screener.log_retention_days'),
                'email_digest_days' => (int) config('alerts.email_digest.ledger_retention_days'),
            ],
            // alerts-email-digest: the privacy policy describes the daily email
            // and names its sending provider only while the feature is active.
            'email_digest' => AlertEmailDigestAvailability::available() ? [
                'provider' => AlertEmailDigestAvailability::provider(),
                'provider_location' => AlertEmailDigestAvailability::providerLocation(),
            ] : null,
            // market-context-ui: the Market Context go-live switch. The SPA
            // shows the header link and the FRED clauses of the legal notice
            // only while it is on.
            'market_context' => (bool) config('market_context.public'),
        ]);
    }

    /**
     * Configured owner fields, trimmed; a blank value becomes null.
     *
     * @return array{name: string|null, tax_id: string|null, address: string|null, email: string|null, registry: string|null}
     */
    private function ownerValues(): array
    {
        $values = [];
        foreach (self::OWNER_FIELDS as $field) {
            $value = trim((string) config("legal.owner.{$field}"));
            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }
}
