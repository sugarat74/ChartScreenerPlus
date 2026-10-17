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
     * A valid `company-card-v2` object for tests and fixtures (no figures:
     * those come from Company Fundamentals).
     *
     * @return array<string, mixed>
     */
    public static function content(string $ticker): array
    {
        return [
            'company' => [
                'name' => 'Fixture Corp',
                'ticker' => $ticker,
                'sector' => 'Information Technology',
                'industry' => 'Semiconductors',
                'description' => 'Designs chips used in data centers.',
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
            'limitations' => 'Figures come from the model and may be outdated.',
            'sources' => ['Company annual reports'],
        ];
    }
}
