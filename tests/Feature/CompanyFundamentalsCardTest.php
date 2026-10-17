<?php

namespace Tests\Feature;

use App\Models\AiCompanyCard;
use App\Models\CompanyFundamental;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\User;
use Database\Factories\AiCompanyCardFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSecEdgar;
use Tests\TestCase;

/**
 * SEC fundamentals in the Company Card API and the grounded outlook
 * (docs/specs/company-fundamentals-sec.md scenarios 1-4 and 7). No SEC or AI
 * provider is ever called: fundamentals come from the database (filled with
 * the command over fixtures where relevant) and the provider is `Http::fake`.
 */
class CompanyFundamentalsCardTest extends TestCase
{
    use FakesSecEdgar;
    use RefreshDatabase;

    private const AI = 'https://ai.example.test/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept-Language', 'es');
        $this->enableSec();
    }

    private function enableAi(bool $rights = false): void
    {
        config()->set('ai_card.enabled', true);
        config()->set('ai_card.rights_verified', $rights);
        config()->set('ai_card.base_url', self::AI);
        config()->set('ai_card.api_key', 'fixture-key');
        config()->set('ai_card.model', 'fixture-model');
    }

    private function fakeAi(string $ticker = 'AAPL'): void
    {
        Http::preventStrayRequests();
        Http::fake(['ai.example.test/*' => Http::response([
            'id' => 'x', 'object' => 'chat.completion', 'model' => 'fixture-model',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode(AiCompanyCardFactory::content($ticker)), 'refusal' => null]]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ])]);
    }

    private function apple(): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => 'AAPL', 'company' => 'Apple Inc.', 'sector' => 'Information Technology', 'exchange' => 'NASDAQ']);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-13', 'close' => 240]);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-14', 'close' => 250]);

        return $instrument;
    }

    /** Fill company_fundamentals with the real command over the fixtures. */
    private function refresh(string ...$tickers): void
    {
        $this->fakeSleeper();
        $this->fakeSec();
        $this->artisan('fundamentals:refresh', ['--ticker' => $tickers])->assertExitCode(0);
    }

    public function test_signed_in_user_gets_sec_fundamentals_even_with_the_ai_disabled(): void
    {
        $this->apple();
        $this->refresh('AAPL');
        Http::fake();

        $response = $this->actingAs(User::factory()->create())->getJson('/api/instruments/AAPL/company-card')->assertOk();

        $response->assertJsonPath('card', null)->assertJsonPath('available', false)
            ->assertJsonPath('fundamentals.schema_version', 'fundamentals-v1')
            ->assertJsonPath('fundamentals.source', 'sec_edgar')
            ->assertJsonPath('fundamentals.currency', 'USD')
            ->assertJsonPath('fundamentals.is_financial', false)
            ->assertJsonPath('fundamentals.period_end', '2026-06-27')
            ->assertJsonPath('fundamentals.filing', ['form' => '10-Q', 'filed_at' => '2026-07-31', 'accession' => '0000320193-26-000020'])
            ->assertJsonPath('fundamentals.pending_filing', null)
            ->assertJsonPath('fundamentals.price', ['close' => 250, 'date' => '2026-10-14'])
            ->assertJsonPath('fundamentals.metrics.eps_diluted_ttm', ['value' => 8.72, 'period' => ['kind' => 'ttm', 'end' => '2026-06-27', 'form' => '10-Q'], 'reason' => null])
            ->assertJsonPath('fundamentals.metrics.revenue_ttm_millions.value', 466823)
            ->assertJsonPath('fundamentals.metrics.revenue_growth_yoy_percent.value', 14.24)
            ->assertJsonPath('fundamentals.metrics.ebitda_millions.value', 167959)
            ->assertJsonPath('fundamentals.metrics.ebitda_margin_percent.value', 35.98)
            ->assertJsonPath('fundamentals.metrics.pe_ttm.value', 28.67)
            ->assertJsonPath('fundamentals.metrics.pe_ttm.period', ['kind' => 'price', 'end' => '2026-10-14', 'form' => null]);
        $this->assertSame([
            'pe_ttm', 'eps_diluted_ttm', 'market_cap_millions', 'revenue_ttm_millions', 'revenue_growth_yoy_percent',
            'ebitda_millions', 'ebitda_margin_percent', 'net_debt_to_ebitda', 'roic_percent', 'dividend_yield_percent',
        ], array_keys($response->json('fundamentals.metrics')));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('fundamentals.fetched_at'));
        Http::assertNothingSent();
    }

    public function test_bank_metrics_are_not_applicable_with_a_reason(): void
    {
        Instrument::factory()->create(['ticker' => 'JPM']);
        $this->refresh('JPM');

        $response = $this->actingAs(User::factory()->create())->getJson('/api/instruments/JPM/company-card')->assertOk()
            ->assertJsonPath('fundamentals.is_financial', true)
            ->assertJsonPath('fundamentals.metrics.eps_diluted_ttm.value', 23.34)
            ->assertJsonPath('fundamentals.metrics.pe_ttm.reason', 'no_price');
        foreach (['ebitda_millions', 'ebitda_margin_percent', 'net_debt_to_ebitda', 'roic_percent'] as $key) {
            $response->assertJsonPath("fundamentals.metrics.{$key}", ['value' => null, 'period' => null, 'reason' => 'financial_sector']);
        }
    }

    public function test_pending_filing_is_exposed(): void
    {
        Instrument::factory()->create(['ticker' => 'XOM']);
        $this->refresh('XOM');

        $this->actingAs(User::factory()->create())->getJson('/api/instruments/XOM/company-card')->assertOk()
            ->assertJsonPath('fundamentals.period_end', '2026-03-31')
            ->assertJsonPath('fundamentals.pending_filing', ['form' => '10-Q', 'filed_at' => '2026-08-03']);
    }

    public function test_visitor_still_gets_401_and_disabled_sec_returns_null_fundamentals(): void
    {
        $instrument = $this->apple();
        CompanyFundamental::factory()->create(['instrument_id' => $instrument->id]);
        Http::fake();

        $this->getJson('/api/instruments/AAPL/company-card')->assertUnauthorized()->assertJsonMissingPath('fundamentals');

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()->assertJsonPath('fundamentals.period_end', '2026-06-27');

        config()->set('fundamentals.enabled', false);
        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertExactJson(['card' => null, 'available' => false, 'fundamentals' => null]);
        config()->set('fundamentals.enabled', true);
        config()->set('fundamentals.user_agent', '');
        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()->assertJsonPath('fundamentals', null);
        Http::assertNothingSent();
    }

    public function test_the_provider_gets_sec_figures_but_no_price_metrics_without_the_rights_flag(): void
    {
        $this->enableAi(rights: false);
        $instrument = $this->apple();
        $this->refresh('AAPL');
        $this->fakeAi();
        $user = User::factory()->create(['email' => 'grounded-user@example.test']);

        $this->actingAs($user)->postJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('card.schema_version', 'company-card-v2')
            ->assertJsonPath('card.fundamentals_period_end', '2026-06-27')
            ->assertJsonPath('card.content.metrics', null)
            ->assertJsonPath('fundamentals.metrics.eps_diluted_ttm.value', 8.72);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'ai.example.test')) {
                return false;
            }
            $context = json_decode($request['messages'][1]['content'], true);
            $metrics = $context['fundamentals']['metrics'];
            $schema = json_encode($request['response_format']['json_schema']['schema']);

            return $context['instrument']['ticker'] === 'AAPL'
                && ! isset($context['technical'])
                && $context['fundamentals']['period_end'] === '2026-06-27'
                && $context['fundamentals']['filing'] === ['form' => '10-Q', 'filed_at' => '2026-07-31']
                && $context['fundamentals']['is_financial'] === false
                && $metrics['eps_diluted_ttm'] === ['value' => 8.72, 'period' => 'TTM to 2026-06-27 (10-Q)']
                && $metrics['ebitda_millions']['value'] == 167959
                && array_keys($metrics) === ['eps_diluted_ttm', 'revenue_ttm_millions', 'revenue_growth_yoy_percent', 'ebitda_millions', 'ebitda_margin_percent', 'net_debt_to_ebitda', 'roic_percent']
                && ! isset($context['fundamentals']['price'])
                && ! str_contains($request->body(), 'pe_ttm')
                && ! str_contains($request->body(), 'market_cap')
                && ! str_contains($request->body(), 'dividend_yield')
                && ! str_contains($request->body(), 'grounded-user@example.test')
                && ! str_contains($schema, 'metrics')
                && str_contains($request['messages'][0]['content'], 'verified figures computed by Chartiko');
        });

        $card = AiCompanyCard::query()->where('instrument_id', $instrument->id)->first();
        $this->assertSame(['company-card-v2', 'company-card-prompt-v3', '0000320193-26-000020'], [$card->schema_version, $card->prompt_version, $card->fundamentals_accession]);
        $this->assertSame('2026-06-27', $card->fundamentals_period_end->format('Y-m-d'));
    }

    public function test_with_the_rights_flag_price_derived_metrics_are_sent_too(): void
    {
        $this->enableAi(rights: true);
        $this->apple();
        $this->refresh('AAPL');
        $this->fakeAi();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/AAPL/company-card')->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'ai.example.test')) {
                return false;
            }
            $context = json_decode($request['messages'][1]['content'], true);

            return $context['fundamentals']['metrics']['pe_ttm'] === ['value' => 28.67, 'period' => 'close of 2026-10-14']
                && isset($context['fundamentals']['metrics']['market_cap_millions'], $context['fundamentals']['metrics']['dividend_yield_percent'])
                && $context['fundamentals']['price'] === ['close' => 250, 'date' => '2026-10-14']
                && isset($context['technical']);
        });
    }

    public function test_without_fundamentals_the_outlook_still_works_and_says_so(): void
    {
        $this->enableAi();
        $this->apple();
        $this->fakeAi();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertJsonPath('fundamentals', null)
            ->assertJsonPath('card.fundamentals_period_end', null);

        Http::assertSent(fn (Request $request): bool => ! isset(json_decode($request['messages'][1]['content'], true)['fundamentals'])
            && str_contains($request['messages'][0]['content'], 'say in "limitations" that no verified financial data was available'));
    }

    public function test_a_new_filing_makes_the_card_stale_and_shows_the_new_period(): void
    {
        $this->enableAi();
        $instrument = $this->apple();
        $fundamentals = CompanyFundamental::factory()->create(['instrument_id' => $instrument->id]);
        AiCompanyCard::factory()->create([
            'instrument_id' => $instrument->id, 'fundamentals_accession' => '0000320193-26-000020', 'fundamentals_period_end' => '2026-06-27',
        ]);
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertJsonPath('card.fresh', true)->assertJsonPath('card.fundamentals_period_end', '2026-06-27');

        // The daily refresh stores the FY2026 10-K.
        $fundamentals->update(['latest_accession' => '0000320193-26-000099', 'latest_form' => '10-K', 'period_end' => '2026-09-26']);

        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertJsonPath('card.fresh', false)
            ->assertJsonPath('card.fundamentals_period_end', '2026-06-27')
            ->assertJsonPath('fundamentals.period_end', '2026-09-26')
            ->assertJsonPath('fundamentals.filing.form', '10-K');
        Http::assertNothingSent();
    }

    public function test_a_card_made_without_fundamentals_is_stale_once_they_exist(): void
    {
        $this->enableAi();
        $instrument = $this->apple();
        AiCompanyCard::factory()->create(['instrument_id' => $instrument->id]);
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertJsonPath('card.fresh', true);
        CompanyFundamental::factory()->create(['instrument_id' => $instrument->id]);
        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertJsonPath('card.fresh', false);
    }

    public function test_v1_cards_with_model_figures_are_never_shown_again(): void
    {
        $this->enableAi();
        $instrument = $this->apple();
        $v1 = AiCompanyCardFactory::content('AAPL') + [
            'metrics' => ['pe_ttm' => ['value' => 99.9, 'currency' => null, 'period' => 'TTM']],
            'data_as_of' => 'FY2025',
        ];
        AiCompanyCard::factory()->create([
            'instrument_id' => $instrument->id, 'schema_version' => 'company-card-v1', 'prompt_version' => 'company-card-prompt-v2', 'content' => $v1,
        ]);
        $this->fakeAi();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->assertOk()->assertJsonPath('card', null);
        $this->actingAs($user)->postJson('/api/instruments/AAPL/company-card')->assertOk()
            ->assertJsonPath('reused', false)->assertJsonPath('card.schema_version', 'company-card-v2');

        $this->assertDatabaseCount('ai_company_cards', 2);
        $this->assertStringNotContainsString('99.9', json_encode($this->actingAs($user)->getJson('/api/instruments/AAPL/company-card')->json()));
    }
}
