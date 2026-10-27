<?php

namespace Tests\Unit;

use App\Services\Screener\ScreenerFilterParser;
use Database\Factories\SignalFactory;
use Tests\TestCase;

/**
 * The Signal vocabulary is a shared contract (technical-indicators-expansion R3):
 * the engine `RULES` registry, the Laravel constant and the es/en labels must
 * list exactly the same 15 types.
 */
class SignalVocabularyTest extends TestCase
{
    /** @return list<string> */
    private function engineTypes(): array
    {
        $source = file_get_contents(base_path('engine/app/signals/rules.py'));
        $this->assertNotFalse($source);

        $registry = substr($source, (int) strpos($source, 'RULES:'));
        preg_match_all('/\(\s*"([a-z_]+)"\s*,\s*[a-z_]+\s*\)/', $registry, $matches);

        return $matches[1];
    }

    public function test_the_engine_registry_and_the_laravel_constant_agree(): void
    {
        $this->assertCount(15, ScreenerFilterParser::SIGNAL_TYPES);
        $this->assertSame($this->engineTypes(), ScreenerFilterParser::SIGNAL_TYPES);
    }

    public function test_every_type_has_an_email_label_in_both_locales(): void
    {
        foreach (['en', 'es'] as $locale) {
            $labels = require base_path("lang/{$locale}/alert_email.php");

            $this->assertSame(
                ScreenerFilterParser::SIGNAL_TYPES,
                array_keys($labels['signals']),
                "{$locale} signal labels",
            );
        }
    }

    public function test_the_factory_only_draws_from_the_vocabulary(): void
    {
        $this->assertContains((new SignalFactory)->definition()['type'], ScreenerFilterParser::SIGNAL_TYPES);
    }
}
