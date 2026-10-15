<?php

namespace Database\Factories;

use App\Models\AiCompanyCard;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCompanyCard>
 */
class AiCompanyCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instrument_id' => Instrument::factory(),
            'locale' => 'es',
            'schema_version' => (string) config('ai_card.schema_version'),
            'prompt_version' => (string) config('ai_card.prompt_version'),
            'model' => 'fixture-model',
            'context_mode' => 'identity',
            'context_as_of_date' => null,
            'content' => fn (array $attributes) => self::content(
                (string) (Instrument::query()->whereKey($attributes['instrument_id'])->value('ticker') ?? 'TEST'),
            ),
            'generated_at' => now(),
        ];
    }

    /**
     * A valid `company-card-v1` object for tests and fixtures.
     *
     * @return array<string, mixed>
     */
    public static function content(string $ticker): array
    {
        $metric = fn (int|float|null $value, ?string $period, ?string $currency = null): array => [
            'value' => $value, 'currency' => $currency, 'period' => $period,
        ];

        return [
            'company' => [
                'name' => 'Fixture Corp',
                'ticker' => $ticker,
                'sector' => 'Information Technology',
                'industry' => 'Semiconductors',
                'description' => 'Designs chips used in data centers.',
            ],
            'metrics' => [
                'pe_ttm' => $metric(45.2, 'TTM Q2 2026'),
                'pe_forward' => $metric(null, null),
                'roic_percent' => $metric(38.5, 'FY2025'),
                'ebitda_millions' => $metric(86000, 'FY2025', 'USD'),
                'ebitda_margin_percent' => $metric(62.1, 'FY2025'),
                'revenue_growth_yoy_percent' => $metric(78, 'FY2025'),
                'net_debt_to_ebitda' => $metric(-0.4, 'FY2025'),
                'market_cap_millions' => $metric(null, null),
                'dividend_yield_percent' => $metric(0.03, 'TTM'),
            ],
            'outlook' => [
                'horizon_months' => 3,
                'stance' => 'neutral',
                'summary' => 'Demand remains strong while valuation is demanding.',
                'macro_context' => 'Rates are expected to stay restrictive.',
                'sector_context' => 'Data center spending keeps growing.',
                'drivers' => ['Data center demand'],
                'risks' => ['Export restrictions'],
                'confidence' => 'medium',
            ],
            'data_as_of' => 'FY2025',
            'limitations' => 'Figures come from the model and may be outdated.',
            'sources' => ['Company annual reports'],
        ];
    }
}
