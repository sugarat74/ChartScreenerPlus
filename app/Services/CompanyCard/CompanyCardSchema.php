<?php

namespace App\Services\CompanyCard;

/**
 * The `company-card-v2` JSON Schema sent to the provider and the hand-written
 * server-side validator applied to every answer before it is stored or shown.
 *
 * v2 carries only the qualitative part (company description and the 3-month
 * outlook): every financial figure comes from Company Fundamentals computed
 * from SEC filings (company-fundamentals-sec), never from the model. The
 * schema carries no price target, fair value, stop, rating or position field.
 * Changing the schema requires bumping `config('ai_card.schema_version')` and
 * `prompt_version`.
 */
class CompanyCardSchema
{
    public const NAME = 'company_card_v2';

    public const STANCES = ['bullish', 'neutral', 'bearish'];

    public const CONFIDENCE = ['low', 'medium', 'high'];

    private const KEYS = ['company', 'outlook', 'limitations', 'sources'];

    /**
     * Strict JSON Schema (every property required, no additional properties).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::KEYS,
            'properties' => [
                'company' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'ticker', 'sector', 'industry', 'description'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'ticker' => ['type' => 'string'],
                        'sector' => $nullableString,
                        'industry' => $nullableString,
                        'description' => $nullableString,
                    ],
                ],
                'outlook' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['horizon_months', 'stance', 'summary', 'macro_context', 'sector_context', 'drivers', 'risks', 'confidence'],
                    'properties' => [
                        'horizon_months' => ['type' => 'integer', 'description' => 'Always 3.'],
                        'stance' => ['type' => 'string', 'enum' => self::STANCES],
                        'summary' => ['type' => 'string'],
                        'macro_context' => ['type' => 'string'],
                        'sector_context' => ['type' => 'string'],
                        'drivers' => $stringList + ['description' => '1 to 5 items.'],
                        'risks' => $stringList + ['description' => '1 to 5 items.'],
                        'confidence' => ['type' => 'string', 'enum' => self::CONFIDENCE],
                    ],
                ],
                'limitations' => ['type' => 'string'],
                'sources' => $stringList + ['description' => '0 to 5 plain-text descriptions, no URLs required.'],
            ],
        ];
    }

    /**
     * Validate and normalize a decoded provider answer.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed> The normalized card content.
     *
     * @throws InvalidCompanyCard
     */
    public static function validate(array $data, string $ticker): array
    {
        self::keys($data, self::KEYS);

        $company = self::object($data['company'], ['name', 'ticker', 'sector', 'industry', 'description']);
        $name = self::string($company['name'], 200);
        $cardTicker = self::string($company['ticker'], 12);
        if (strcasecmp($cardTicker, trim($ticker)) !== 0) {
            throw new InvalidCompanyCard('ticker mismatch');
        }

        $outlook = self::object($data['outlook'], ['horizon_months', 'stance', 'summary', 'macro_context', 'sector_context', 'drivers', 'risks', 'confidence']);
        if (! is_int($outlook['horizon_months']) || $outlook['horizon_months'] !== 3) {
            throw new InvalidCompanyCard('horizon');
        }

        return [
            'company' => [
                'name' => $name,
                'ticker' => strtoupper(trim($ticker)),
                'sector' => self::nullableString($company['sector'], 120),
                'industry' => self::nullableString($company['industry'], 120),
                'description' => self::nullableProse($company['description'], 600),
            ],
            'outlook' => [
                'horizon_months' => 3,
                'stance' => self::enum($outlook['stance'], self::STANCES),
                'summary' => self::prose($outlook['summary'], 700),
                'macro_context' => self::prose($outlook['macro_context'], 500),
                'sector_context' => self::prose($outlook['sector_context'], 500),
                'drivers' => self::list($outlook['drivers'], 1, 5),
                'risks' => self::list($outlook['risks'], 1, 5),
                'confidence' => self::enum($outlook['confidence'], self::CONFIDENCE),
            ],
            'limitations' => self::prose($data['limitations'], 400),
            'sources' => self::list($data['sources'], 0, 5),
        ];
    }

    /**
     * @param  list<string>  $expected
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $expected): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidCompanyCard('object expected');
        }
        self::keys($value, $expected);

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private static function keys(array $value, array $expected): void
    {
        $actual = array_map('strval', array_keys($value));
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidCompanyCard('key set');
        }
    }

    private static function string(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidCompanyCard('string expected');
        }
        $clean = self::clean($value);
        if ($clean === '' || mb_strlen($clean) > $max) {
            throw new InvalidCompanyCard('string length');
        }

        return $clean;
    }

    /**
     * Free text: models overshoot length limits by a few words, so an
     * over-long value is shortened at a sentence end (or a word, with an
     * ellipsis) instead of rejecting the whole card. Identifiers and other
     * short fields keep the strict `string()` check.
     */
    private static function prose(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidCompanyCard('string expected');
        }
        $clean = self::clean($value);
        if ($clean === '') {
            throw new InvalidCompanyCard('string length');
        }

        return self::fit($clean, $max);
    }

    private static function nullableProse(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidCompanyCard('string or null expected');
        }
        $clean = self::clean($value);

        return $clean === '' ? null : self::fit($clean, $max);
    }

    private static function fit(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $head = mb_substr($text, 0, $max);
        // Prefer the last full sentence when it keeps most of the text.
        if (preg_match('/^(.*[.!?])(?:\s|$)/us', $head, $match) === 1 && mb_strlen($match[1]) >= (int) ($max * 0.6)) {
            return $match[1];
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,;:-').'…';
    }

    private static function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidCompanyCard('string or null expected');
        }
        $clean = self::clean($value);
        if (mb_strlen($clean) > $max) {
            throw new InvalidCompanyCard('string length');
        }

        return $clean === '' ? null : $clean;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function enum(mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidCompanyCard('enum');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value, int $min, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new InvalidCompanyCard('list size');
        }

        return array_map(fn (mixed $item): string => self::prose($item, 200), $value);
    }

    /** Trim and replace control characters (including newlines) with spaces. */
    private static function clean(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        if (! is_string($value)) {
            throw new InvalidCompanyCard('encoding');
        }

        return trim(preg_replace('/ {2,}/', ' ', $value) ?? $value);
    }
}
