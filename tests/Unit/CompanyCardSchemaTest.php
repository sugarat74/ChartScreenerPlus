<?php

namespace Tests\Unit;

use App\Services\CompanyCard\CompanyCardSchema;
use App\Services\CompanyCard\InvalidCompanyCard;
use Database\Factories\AiCompanyCardFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `company-card-v2` schema and its server-side validator
 * (docs/specs/ai-company-card.md -> Card JSON schema; v2 drops every
 * financial figure, docs/specs/company-fundamentals-sec.md decision 7).
 */
class CompanyCardSchemaTest extends TestCase
{
    public function test_valid_card_is_accepted_and_normalized(): void
    {
        $card = AiCompanyCardFactory::content('nvda');
        $card['company']['description'] = "  Designs\u{0000} chips\nfor data centers.  ";
        $card['company']['industry'] = '   ';

        $result = CompanyCardSchema::validate($card, 'NVDA');

        $this->assertSame('NVDA', $result['company']['ticker']);
        $this->assertSame('Designs chips for data centers.', $result['company']['description']);
        $this->assertNull($result['company']['industry']);
        $this->assertSame(['company', 'outlook', 'limitations', 'sources'], array_keys($result));
        $this->assertSame(array_keys(AiCompanyCardFactory::content('X')), array_keys($result));
    }

    public function test_schema_is_strict_and_has_no_advice_fields(): void
    {
        $schema = CompanyCardSchema::schema();
        $check = function (array $node) use (&$check): void {
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties']);
                $this->assertSame(array_keys($node['properties']), $node['required']);
                foreach ($node['properties'] as $child) {
                    $check($child);
                }
            }
        };
        $check($schema);

        $this->assertSame('company_card_v2', CompanyCardSchema::NAME);
        $this->assertSame(['company', 'outlook', 'limitations', 'sources'], $schema['required']);
        $encoded = json_encode($schema);
        // No figures from the model: metrics come only from SEC fundamentals.
        foreach (['metrics', 'data_as_of', 'pe_ttm', 'ebitda', 'currency'] as $figure) {
            $this->assertStringNotContainsString($figure, $encoded);
        }
        foreach (['target', 'fair_value', 'stop', 'rating', 'recommendation', 'position'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> */
    public static function invalidCards(): array
    {
        return [
            'missing top key' => [function (array $c) { unset($c['sources']);

                return $c; }],
            'extra top key' => [fn (array $c) => $c + ['price_target' => 1]],
            'missing company key' => [function (array $c) { unset($c['company']['industry']);

                return $c; }],
            'v1 metrics block' => [fn (array $c) => $c + ['metrics' => ['pe_ttm' => ['value' => 45, 'currency' => null, 'period' => 'TTM']]]],
            'v1 data_as_of' => [fn (array $c) => $c + ['data_as_of' => 'FY2025']],
            'ticker mismatch' => [function (array $c) { $c['company']['ticker'] = 'AMD';

                return $c; }],
            'empty name' => [function (array $c) { $c['company']['name'] = ' ';

                return $c; }],
            'horizon not 3' => [function (array $c) { $c['outlook']['horizon_months'] = 6;

                return $c; }],
            'horizon as string' => [function (array $c) { $c['outlook']['horizon_months'] = '3';

                return $c; }],
            'bad stance' => [function (array $c) { $c['outlook']['stance'] = 'buy';

                return $c; }],
            'bad confidence' => [function (array $c) { $c['outlook']['confidence'] = 'certain';

                return $c; }],
            'no drivers' => [function (array $c) { $c['outlook']['drivers'] = [];

                return $c; }],
            'six risks' => [function (array $c) { $c['outlook']['risks'] = array_fill(0, 6, 'risk');

                return $c; }],
            'drivers as object' => [function (array $c) { $c['outlook']['drivers'] = ['a' => 'x'];

                return $c; }],
            'six sources' => [function (array $c) { $c['sources'] = array_fill(0, 6, 'source');

                return $c; }],
            'non-string source' => [function (array $c) { $c['sources'] = [['url' => 'x']];

                return $c; }],
        ];
    }

    public function test_overlong_free_text_is_shortened_not_rejected(): void
    {
        $card = AiCompanyCardFactory::content('NVDA');
        $sentence = 'La demanda de servicios mantiene el crecimiento del grupo en el trimestre. ';
        $card['outlook']['summary'] = str_repeat($sentence, 12);
        $card['limitations'] = str_repeat('palabra ', 80);
        $card['company']['description'] = str_repeat('b', 650);
        $card['outlook']['risks'] = [str_repeat('riesgo ', 40)];

        $content = CompanyCardSchema::validate($card, 'NVDA');

        $this->assertLessThanOrEqual(700, mb_strlen($content['outlook']['summary']));
        $this->assertStringEndsWith('trimestre.', $content['outlook']['summary']);
        $this->assertLessThanOrEqual(400, mb_strlen($content['limitations']));
        $this->assertStringEndsWith('palabra…', $content['limitations']);
        $this->assertSame(600, mb_strlen($content['company']['description']));
        $this->assertStringEndsWith('…', $content['company']['description']);
        $this->assertLessThanOrEqual(200, mb_strlen($content['outlook']['risks'][0]));
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidCards')]
    public function test_invalid_card_is_rejected(callable $mutate): void
    {
        $this->expectException(InvalidCompanyCard::class);

        CompanyCardSchema::validate($mutate(AiCompanyCardFactory::content('NVDA')), 'NVDA');
    }
}
