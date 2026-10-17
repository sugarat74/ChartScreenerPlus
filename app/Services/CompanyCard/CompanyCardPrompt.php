<?php

namespace App\Services\CompanyCard;

/**
 * Messages for one Company Card request (`company-card-prompt-v3`).
 *
 * The user message is only the JSON context from `CompanyCardContextBuilder`;
 * no user-supplied text ever reaches the provider. The model writes only the
 * qualitative part (business description and the 3-month outlook); the
 * financial figures are computed by Chartiko from SEC filings and given to it
 * as verified context. Any change to these instructions requires bumping
 * `config('ai_card.prompt_version')`.
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
        $fundamentals = isset($context['fundamentals'])
            ? 'The "fundamentals" object holds verified figures computed by Chartiko from the company\'s SEC 10-K/10-Q filings, each with the period it refers to (a metric with "reason" instead of "value" is not available or does not apply). Base the outlook on these figures and on general macro and sector knowledge. You may cite a given figure with its period, but never state any other financial figure (revenue, margins, multiples, debt, growth or prices) and never contradict the given ones.'
            : 'No verified financial figures are available for this company: do not state any financial figure (revenue, margins, multiples, debt, growth or prices), write a qualitative outlook only, and say in "limitations" that no verified financial data was available.';
        $technical = isset($context['technical'])
            ? 'The "technical" object holds the latest daily technical data Chartiko stores for this stock; you may use it for context, but do not restate it as a forecast.'
            : 'No technical data is supplied.';

        $system = implode("\n", [
            'You are a financial summarizer for Chartiko, a technical stock screener. You write the qualitative part of a company card for one US-listed stock identified in the user message: a short business description and a prominent three-month outlook.',
            "Today is {$today}. Write every free-text field in {$language}. Company, ticker, sector and industry names are proper names: keep them as they are commonly written.",
            'Rules:',
            "- {$fundamentals}",
            '- "outlook" is a qualitative three-month scenario (horizon_months is always 3) of the macro and sector context and how it may affect the company. "stance" describes the base-case scenario (bullish, neutral or bearish), not a recommendation. Give 1 to 5 drivers and 1 to 5 risks; "confidence" is your confidence in that scenario.',
            '- Never give price targets, fair values, stop levels, buy/sell/hold ratings, position sizes or personal investment advice, and never tell the reader what to do.',
            '- "limitations" states briefly what may be wrong or outdated in this card. "sources" lists up to 5 plain-text descriptions of the kind of public information your text relies on (no invented URLs).',
            '- Keep within these lengths (characters): company.description 600, outlook.summary 700, outlook.macro_context and outlook.sector_context 500 each, limitations 400, each driver, risk and source 200. Be concise; longer text is cut.',
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
