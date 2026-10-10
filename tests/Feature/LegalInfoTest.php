<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Public legal facts (legal-compliance-eu): the owner's identity is never
 * returned until every required field is configured, and retention figures
 * follow the configuration the application enforces.
 */
class LegalInfoTest extends TestCase
{
    private const OWNER = [
        'name' => 'Owner Test',
        'tax_id' => '00000000T',
        'address' => 'Calle Prueba 1, 28000 Madrid',
        'email' => 'privacy@example.test',
        'registry' => null,
    ];

    public function test_unpublished_without_owner_data(): void
    {
        config(['legal.owner' => array_fill_keys(array_keys(self::OWNER), null)]);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('published', false)
            ->assertJsonPath('owner', null)
            ->assertJsonStructure(['updated_at', 'retention' => ['sign_in_activity_days', 'session_minutes', 'backup_days', 'server_log_days']]);
    }

    public function test_any_missing_required_field_keeps_it_unpublished_and_leaks_nothing(): void
    {
        foreach (['name', 'tax_id', 'address', 'email'] as $missing) {
            config(['legal.owner' => [...self::OWNER, $missing => '   ']]);

            $response = $this->getJson('/api/legal')->assertOk();

            $response->assertJsonPath('published', false)->assertJsonPath('owner', null);
            foreach (array_filter(self::OWNER) as $value) {
                $this->assertStringNotContainsString($value, $response->getContent(), "missing {$missing}");
            }
        }
    }

    public function test_published_with_all_required_owner_fields(): void
    {
        config(['legal.owner' => [...self::OWNER, 'name' => '  Owner Test  ']]);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('published', true)
            ->assertJsonPath('owner.name', 'Owner Test')
            ->assertJsonPath('owner.tax_id', '00000000T')
            ->assertJsonPath('owner.email', 'privacy@example.test')
            ->assertJsonPath('owner.registry', null);
    }

    public function test_retention_figures_come_from_configuration(): void
    {
        config([
            'admin.activity_retention_days' => 45,
            'session.lifetime' => 30,
            'legal.backup_retention_days' => 7,
            'legal.server_log_retention_days' => 10,
            'legal.updated_at' => '2026-10-10',
        ]);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('updated_at', '2026-10-10')
            ->assertJsonPath('retention.sign_in_activity_days', 45)
            ->assertJsonPath('retention.session_minutes', 30)
            ->assertJsonPath('retention.backup_days', 7)
            ->assertJsonPath('retention.server_log_days', 10);
    }

    public function test_endpoint_is_public_and_needs_no_session(): void
    {
        $this->getJson('/api/legal')->assertOk()->assertHeaderMissing('Set-Cookie');
    }
}
