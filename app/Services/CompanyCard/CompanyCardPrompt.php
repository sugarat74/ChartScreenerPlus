<?php

namespace App\Services\CompanyCard;

/**
 * Messages for one Company Card request (`company-card-prompt-v2`).
 *
 * The user message is only the JSON context from `CompanyCardContextBuilder`;
 * no user-supplied text ever reaches the provider. Any change to these
 * instructions requires bumping `config('ai_card.prompt_version')`.
 */
class CompanyCardPrompt
{
    private const LANGUAGES = ['es' => 'Spanish (Spain)', 'en' => 'English'];

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{role: string, content: string}>
     */
    public static function messages(array $context, string $locale, string $today): array
    {
        $language = self::LANGUAGES[$locale] ?? self::LANGUAGES['en'];
        $technical = isset($context['technical'])
            ? 'The "technical" object holds the latest daily technical data Chartiko stores for this stock; you may use it for context, but do not restate it as a forecast.'
            : 'No price or technical data is supplied.';

        $system = implode("\n", [
            'You are a financial data summarizer for Chartiko, a technical stock screener. You write a short, factual company card for one US-listed stock identified in the user message.',
            "Today is {$today}. Write every free-text field in {$language}. Company, ticker, sector and industry names are proper names: keep them as they are commonly written.",
            'Rules:',
            '- Use only knowledge you are confident about. For each metric give the most recent figure you know reliably, with the period it refers to, even if that period is not the latest one (the reader sees the period). Set "value" to null only when you do not know a reliable figure; never guess or extrapolate.',
            '- Every non-null metric needs a "period" naming the fiscal period or date it refers to (e.g. "FY2025", "TTM to Q2 2026"). "data_as_of" is the latest period your figures refer to, or null.',
            '- Units are fixed by the metric key: pe_ttm, pe_forward and net_debt_to_ebitda are ratios (times); *_percent fields are percentages (5.2 means 5.2%); *_millions fields are millions of "currency" (ISO 4217 code, required only for those amounts). Use null currency for ratios and percentages.',
            '- "outlook" is a qualitative three-month view (horizon_months is always 3) of the macro and sector context and how it may affect the company. "stance" describes the base-case scenario (bullish, neutral or bearish), not a recommendation. Give 1 to 5 drivers and 1 to 5 risks.',
            '- Never give price targets, fair values, stop levels, buy/sell/hold ratings, position sizes or personal investment advice, and never tell the reader what to do.',
            '- "limitations" states briefly what may be wrong or outdated in this card. "sources" lists up to 5 plain-text descriptions of the kind of public information your knowledge comes from (no invented URLs).',
            '- Keep within these lengths (characters): company.description 600, outlook.summary 700, outlook.macro_context and outlook.sector_context 500 each, limitations 400, each driver, risk and source 200, every period and data_as_of 40. Be concise; longer text is cut.',
            '- company.ticker must equal the ticker in the user message.',
            "- {$technical}",
            'Answer with the JSON object only.',
        ]);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ];
    }
}
