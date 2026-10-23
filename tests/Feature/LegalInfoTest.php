<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Public legal facts (legal-compliance-eu, legal-privacy-publication): the
 * privacy policy is published with name + valid email, the legal notice also
 * needs tax id + address, and no owner value leaves the server unless the page
 * that needs it is published. Retention figures follow configuration.
 */
class LegalInfoTest extends TestCase
{
    private const OWNER = [
        'name' => 'Owner Test',
        'tax_id' => '00000000T',
        'address' => 'Calle Prueba 1, 28000 Madrid',
        'email' => 'privacy@example.test',
        'registry' => 'Registro Mercantil Tomo 1',
    ];

    private function setOwner(array $owner): void
    {
        config(['legal.owner' => [...array_fill_keys(array_keys(self::OWNER), null), ...$owner]]);
    }

    private function assertBodyLacks(string $body, array $values, string $context): void
    {
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $body, $context);
        }
    }

    public function test_nothing_published_without_owner_data(): void
    {
        $this->setOwner([]);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('privacy_published', false)
            ->assertJsonPath('notice_published', false)
            ->assertJsonPath('owner', null)
            ->assertJsonMissingPath('published')
            ->assertJsonStructure(['updated_at', 'retention' => ['sign_in_activity_days', 'session_minutes', 'backup_days', 'server_log_days', 'notification_days', 'ai_card_log_days', 'ai_screener_log_days']]);
    }

    public function test_name_and_email_publish_privacy_only(): void
    {
        $this->setOwner(['name' => 'Owner Test', 'email' => 'privacy@example.test']);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('privacy_published', true)
            ->assertJsonPath('notice_published', false)
            ->assertJsonPath('owner', [
                'name' => 'Owner Test',
                'email' => 'privacy@example.test',
                'tax_id' => null,
                'address' => null,
                'registry' => null,
            ])
            ->assertJsonMissingPath('published');
    }

    public function test_a_partial_notice_field_is_withheld(): void
    {
        foreach (['tax_id', 'address'] as $present) {
            $this->setOwner(['name' => 'Owner Test', 'email' => 'privacy@example.test', $present => self::OWNER[$present], 'registry' => self::OWNER['registry']]);

            $response = $this->getJson('/api/legal')
                ->assertOk()
                ->assertJsonPath('privacy_published', true)
                ->assertJsonPath('notice_published', false)
                ->assertJsonPath('owner.tax_id', null)
                ->assertJsonPath('owner.address', null)
                ->assertJsonPath('owner.registry', null);

            $this->assertBodyLacks($response->getContent(), [self::OWNER[$present], self::OWNER['registry']], "only {$present}");
        }
    }

    public function test_every_field_publishes_both_pages(): void
    {
        $this->setOwner(self::OWNER);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('privacy_published', true)
            ->assertJsonPath('notice_published', true)
            ->assertJsonPath('owner', [
                'name' => 'Owner Test',
                'email' => 'privacy@example.test',
                'tax_id' => '00000000T',
                'address' => 'Calle Prueba 1, 28000 Madrid',
                'registry' => 'Registro Mercantil Tomo 1',
            ])
            ->assertJsonMissingPath('published');
    }

    public function test_registry_is_null_when_not_configured(): void
    {
        $this->setOwner([...self::OWNER, 'registry' => '  ']);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('notice_published', true)
            ->assertJsonPath('owner.registry', null);
    }

    public function test_missing_name_or_email_leaks_nothing(): void
    {
        foreach (['name', 'email'] as $missing) {
            foreach ([null, '', '   '] as $blank) {
                $this->setOwner([...self::OWNER, $missing => $blank]);

                $response = $this->getJson('/api/legal')
                    ->assertOk()
                    ->assertJsonPath('privacy_published', false)
                    ->assertJsonPath('notice_published', false)
                    ->assertJsonPath('owner', null);

                $this->assertBodyLacks($response->getContent(), array_filter(self::OWNER, fn ($value) => $value !== null), "missing {$missing}");
            }
        }
    }

    public function test_an_invalid_email_publishes_nothing(): void
    {
        foreach (['not-an-email', 'privacy@', 'a b@example.test'] as $email) {
            $this->setOwner([...self::OWNER, 'email' => $email]);

            $response = $this->getJson('/api/legal')
                ->assertOk()
                ->assertJsonPath('privacy_published', false)
                ->assertJsonPath('notice_published', false)
                ->assertJsonPath('owner', null);

            $this->assertBodyLacks($response->getContent(), [self::OWNER['name'], self::OWNER['tax_id'], self::OWNER['address'], self::OWNER['registry'], $email], "email {$email}");
        }
    }

    public function test_values_are_trimmed(): void
    {
        $this->setOwner(['name' => '  Owner Test  ', 'email' => " privacy@example.test\t", 'tax_id' => ' 00000000T ', 'address' => ' Calle Prueba 1, 28000 Madrid ']);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('notice_published', true)
            ->assertJsonPath('owner.name', 'Owner Test')
            ->assertJsonPath('owner.email', 'privacy@example.test')
            ->assertJsonPath('owner.tax_id', '00000000T')
            ->assertJsonPath('owner.address', 'Calle Prueba 1, 28000 Madrid');
    }

    public function test_retention_figures_come_from_configuration(): void
    {
        config([
            'admin.activity_retention_days' => 45,
            'session.lifetime' => 30,
            'legal.backup_retention_days' => 7,
            'legal.server_log_retention_days' => 10,
            'alerts.notification_retention_days' => 30,
            'ai_card.log_retention_days' => 60,
            'ai_screener.log_retention_days' => 45,
            'legal.updated_at' => '2026-10-10',
        ]);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('updated_at', '2026-10-10')
            ->assertJsonPath('retention.sign_in_activity_days', 45)
            ->assertJsonPath('retention.session_minutes', 30)
            ->assertJsonPath('retention.backup_days', 7)
            ->assertJsonPath('retention.server_log_days', 10)
            ->assertJsonPath('retention.notification_days', 30)
            ->assertJsonPath('retention.ai_card_log_days', 60)
            ->assertJsonPath('retention.ai_screener_log_days', 45);
    }

    public function test_endpoint_is_public_and_needs_no_session(): void
    {
        $this->getJson('/api/legal')->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_account_email_is_named_only_while_email_verification_is_required(): void
    {
        // auth-email-verification: dormant by default.
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('account_email', null);

        config(['auth.verification.enabled' => true, 'mail.provider' => 'Brevo', 'mail.provider_location' => 'UE']);
        $this->getJson('/api/legal')->assertOk()
            ->assertJsonPath('account_email.provider', 'Brevo')
            ->assertJsonPath('account_email.provider_location', 'UE');

        // The flag alone, without a provider location, is still dormant.
        config(['mail.provider_location' => '']);
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('account_email', null);
    }

    public function test_market_context_follows_the_go_live_flag(): void
    {
        config(['market_context.public' => false]);
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('market_context', false);

        config(['market_context.public' => true]);
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('market_context', true);
    }
}
