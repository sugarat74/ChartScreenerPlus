<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Public facts the legal pages render (docs/specs/legal-compliance-eu.md).
 *
 * The owner's identity is returned only once every required field is
 * configured; until then `published` is false and the SPA withholds the
 * privacy policy and legal notice instead of showing placeholders. Retention
 * figures come from the same configuration the application enforces.
 */
class LegalController extends Controller
{
    private const REQUIRED_OWNER_FIELDS = ['name', 'tax_id', 'address', 'email'];

    public function show(): JsonResponse
    {
        $owner = $this->owner();

        return response()->json([
            'published' => $owner !== null,
            'owner' => $owner,
            'updated_at' => config('legal.updated_at'),
            'retention' => [
                'sign_in_activity_days' => (int) config('admin.activity_retention_days'),
                'session_minutes' => (int) config('session.lifetime'),
                'backup_days' => (int) config('legal.backup_retention_days'),
                'server_log_days' => (int) config('legal.server_log_retention_days'),
                'notification_days' => (int) config('alerts.notification_retention_days'),
            ],
        ]);
    }

    /**
     * @return array{name: string, tax_id: string, address: string, email: string, registry: string|null}|null
     */
    private function owner(): ?array
    {
        $values = [];
        foreach ([...self::REQUIRED_OWNER_FIELDS, 'registry'] as $field) {
            $value = trim((string) config("legal.owner.{$field}"));
            $values[$field] = $value === '' ? null : $value;
        }

        foreach (self::REQUIRED_OWNER_FIELDS as $field) {
            if ($values[$field] === null) {
                return null;
            }
        }

        return $values;
    }
}
