<?php

namespace Tests\Feature;

use App\Models\AiCompanyCard;
use App\Models\AiCompanyCardGeneration;
use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\User;
use App\Services\CompanyCard\CompanyCardService;
use Database\Factories\AiCompanyCardFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AI Company Card API (docs/specs/ai-company-card.md, scenarios 1-6).
 * Every provider interaction is `Http::fake`; no real AI API is ever called.
 */
class CompanyCardFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://ai.example.test/v1';

    protected function setUp(): void
    {
        parent::setUp();
        // Symfony's test requests default to `Accept-Language: en-us`; use the
        // product default (Spanish) unless a test asks for English.
        $this->withHeader('Accept-Language', 'es');
    }

    private function enable(): void
    {
        config()->set('ai_card.enabled', true);
        config()->set('ai_card.rights_verified', false);
        config()->set('ai_card.base_url', self::URL);
        config()->set('ai_card.api_key', 'fixture-key');
        config()->set('ai_card.model', 'fixture-model');
        config()->set('ai_card.response_format', 'json_schema');
        config()->set('ai_card.response_format_fallback', true);
    }

    private function nvda(): Instrument
    {
        return Instrument::factory()->create([
            'ticker' => 'NVDA', 'company' => 'NVIDIA Corporation',
            'sector' => 'Information Technology', 'exchange' => 'NASDAQ',
        ]);
    }

    /**
     * An OpenAI-style Chat Completions answer.
     *
     * @param  array<string, mixed>|string  $content
     * @return array<string, mixed>
     */
    private function completion(array|string $content, string $finish = 'stop', ?string $refusal = null): array
    {
        return [
            'id' => 'chatcmpl-fixture',
            'object' => 'chat.completion',
            'model' => 'fixture-model',
            'choices' => [[
                'index' => 0,
                'finish_reason' => $finish,
                'message' => [
                    'role' => 'assistant',
                    'content' => is_string($content) ? $content : json_encode($content),
                    'refusal' => $refusal,
                ],
            ]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 340, 'total_tokens' => 460],
        ];
    }

    private function fakeValid(string $ticker = 'NVDA'): void
    {
        Http::fake(['ai.example.test/*' => Http::response($this->completion(AiCompanyCardFactory::content($ticker)))]);
    }

    public function test_guest_gets_401_without_provider_call_or_log(): void
    {
        $this->enable();
        $this->nvda();
        Http::fake();

        $this->getJson('/api/instruments/NVDA/company-card')->assertUnauthorized();
        $this->postJson('/api/instruments/NVDA/company-card')->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_company_card_generations', 0);
    }

    public function test_unknown_ticker_is_a_json_404(): void
    {
        $this->enable();
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/zzzz/company-card')
            ->assertNotFound()->assertJsonPath('message', __('messages.instrument_not_found', [], 'es'));
        $this->actingAs($user)->postJson('/api/instruments/ZZZZ/company-card')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_generates_once_with_strict_schema_and_reuses_for_another_user(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $this->fakeValid();
        $first = User::factory()->create(['email' => 'first-user@example.test']);

        $response = $this->actingAs($first)->withHeader('Accept-Language', 'es')
            ->postJson('/api/instruments/nvda/company-card');
        $response->assertOk()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('card.ticker', 'NVDA')
            ->assertJsonPath('card.locale', 'es')
            ->assertJsonPath('card.schema_version', 'company-card-v2')
            ->assertJsonPath('card.model', 'fixture-model')
            ->assertJsonPath('card.fresh', true)
            ->assertJsonPath('card.context_mode', 'identity')
            ->assertJsonPath('card.context_as_of_date', null)
            ->assertJsonPath('card.content.metrics', null)
            ->assertJsonPath('card.fundamentals_period_end', null)
            ->assertJsonPath('fundamentals', null)
            ->assertJsonPath('card.content.outlook.horizon_months', 3);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('card.generated_at'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($first): bool {
            $body = $request->data();
            $raw = $request->body();

            return $request->url() === self::URL.'/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer fixture-key')
                && $body['model'] === 'fixture-model'
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['strict'] === true
                && $body['response_format']['json_schema']['name'] === 'company_card_v2'
                && $body['max_tokens'] === config('ai_card.max_tokens')
                && $body['temperature'] === 0.2
                && ! str_contains($raw, 'first-user@example.test')
                && ! str_contains($raw, '"user_id"')
                && str_contains($body['messages'][0]['content'], 'Spanish');
        });

        $second = User::factory()->create();
        $this->actingAs($second)->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('reused', true)->assertJsonPath('card.content.company.name', 'Fixture Corp');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_company_cards', 1);
        $this->assertDatabaseCount('ai_company_card_generations', 1);
        $this->assertDatabaseHas('ai_company_card_generations', [
            'instrument_id' => $instrument->id, 'user_id' => $first->id, 'status' => 'succeeded',
            'response_format' => 'json_schema', 'prompt_tokens' => 120, 'completion_tokens' => 340, 'total_tokens' => 460,
        ]);
    }

    public function test_get_only_reads_the_stored_card_for_the_request_locale(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')->assertOk()->assertExactJson(['card' => null, 'available' => true, 'fundamentals' => null]);

        AiCompanyCard::factory()->create(['instrument_id' => $instrument->id, 'locale' => 'en']);
        $this->actingAs($user)->withHeader('Accept-Language', 'es')->getJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card', null);
        $this->actingAs($user)->withHeader('Accept-Language', 'en-GB')->getJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.locale', 'en')->assertJsonPath('card.fresh', true);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_company_card_generations', 0);
    }

    public function test_locales_are_generated_and_cached_separately(): void
    {
        $this->enable();
        $this->nvda();
        $this->fakeValid();
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Accept-Language', 'es')->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.locale', 'es')->assertJsonPath('reused', false);
        $this->actingAs($user)->withHeader('Accept-Language', 'en')->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.locale', 'en')->assertJsonPath('reused', false);
        $this->actingAs($user)->withHeader('Accept-Language', 'en')->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('reused', true);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][0]['content'], 'English'));
        $this->assertDatabaseCount('ai_company_cards', 2);
    }

    public function test_json_schema_400_falls_back_once_to_json_object(): void
    {
        $this->enable();
        $this->nvda();
        Http::fake(['ai.example.test/*' => Http::sequence()
            ->push(['error' => ['message' => 'response_format json_schema is not supported']], 400)
            ->push($this->completion(AiCompanyCardFactory::content('NVDA')))]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')->assertOk()->assertJsonPath('reused', false);

        Http::assertSentCount(2);
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertSame('json_schema', $sent[0]['response_format']['type']);
        $this->assertSame(['type' => 'json_object'], $sent[1]['response_format']);
        $this->assertStringContainsString('JSON Schema', $sent[1]['messages'][0]['content']);
        $this->assertStringContainsString('"horizon_months"', $sent[1]['messages'][0]['content']);
        $this->assertDatabaseCount('ai_company_cards', 1);
        $this->assertDatabaseHas('ai_company_card_generations', ['status' => 'succeeded', 'response_format' => 'json_object']);
    }

    public function test_json_schema_400_without_fallback_is_provider_unavailable(): void
    {
        $this->enable();
        config()->set('ai_card.response_format_fallback', false);
        $this->nvda();
        Http::fake(['ai.example.test/*' => Http::response(['error' => ['message' => 'unsupported']], 400)]);

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_company_cards', 0);
        $this->assertDatabaseHas('ai_company_card_generations', ['status' => 'provider_error', 'error_code' => 'provider_unavailable']);
    }

    public function test_json_object_mode_skips_the_json_schema_attempt(): void
    {
        $this->enable();
        config()->set('ai_card.response_format', 'json_object');
        config()->set('ai_card.max_tokens_param', 'max_completion_tokens');
        config()->set('ai_card.temperature', null);
        $this->nvda();
        $this->fakeValid();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['response_format'] === ['type' => 'json_object']
            && $request['max_completion_tokens'] === config('ai_card.max_tokens')
            && ! array_key_exists('max_tokens', $request->data())
            && ! array_key_exists('temperature', $request->data()));
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> */
    public static function invalidOutputs(): array
    {
        return [
            'not json' => [fn (array $card) => ['content' => 'Sorry, here is the card: {broken']],
            'missing key' => [function (array $card) {
                unset($card['outlook']['risks']);

                return ['content' => $card];
            }],
            'v1 metrics from the model' => [fn (array $card) => ['content' => $card + ['metrics' => ['pe_ttm' => ['value' => 45, 'currency' => null, 'period' => 'TTM']]]]],
            'extra key' => [fn (array $card) => ['content' => $card + ['price_target' => 250]]],
            'extra nested key' => [function (array $card) {
                $card['outlook']['rating'] = 'buy';

                return ['content' => $card];
            }],
            'wrong ticker' => [function (array $card) {
                $card['company']['ticker'] = 'AMD';

                return ['content' => $card];
            }],
            'horizon six months' => [function (array $card) {
                $card['outlook']['horizon_months'] = 6;

                return ['content' => $card];
            }],
            'truncated' => [fn (array $card) => ['content' => $card, 'finish' => 'length']],
            'refusal' => [fn (array $card) => ['content' => $card, 'refusal' => 'I cannot help with that.']],
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidOutputs')]
    public function test_invalid_provider_output_is_rejected_and_never_stored(callable $mutate): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $old = AiCompanyCard::factory()->create([
            'instrument_id' => $instrument->id, 'locale' => 'es', 'model' => 'older-model',
            'generated_at' => now()->subDays(30),
        ]);
        $answer = $mutate(AiCompanyCardFactory::content('NVDA'));
        Http::fake(['ai.example.test/*' => Http::response($this->completion($answer['content'], $answer['finish'] ?? 'stop', $answer['refusal'] ?? null))]);

        $this->actingAs(User::factory()->create())->withHeader('Accept-Language', 'es')
            ->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)
            ->assertJsonPath('code', 'invalid_provider_response')
            ->assertJsonPath('message', __('company_card.invalid_provider_response', [], 'es'));

        $this->assertDatabaseCount('ai_company_cards', 1);
        $this->assertEquals($old->fresh()->content, $old->content);
        $this->assertSame('older-model', $old->fresh()->model);
        $this->assertDatabaseCount('ai_company_card_generations', 1);
        $this->assertDatabaseHas('ai_company_card_generations', [
            'status' => 'invalid_response', 'error_code' => 'invalid_provider_response', 'total_tokens' => 460,
        ]);
    }

    public function test_timeout_and_server_errors_are_provider_unavailable_and_keep_the_old_card(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        AiCompanyCard::factory()->create(['instrument_id' => $instrument->id, 'generated_at' => now()->subDays(8)]);
        $user = User::factory()->create();

        Http::fake(['ai.example.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');

        Http::fake(['ai.example.test/*' => Http::response(['error' => 'overloaded'], 502)]);
        $this->actingAs($user)->withHeader('Accept-Language', 'en')->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('message', __('company_card.provider_unavailable', [], 'en'));

        $this->assertSame(2, AiCompanyCardGeneration::query()->where('status', 'provider_error')->count());
        $this->actingAs($user)->withHeader('Accept-Language', 'es')->getJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.fresh', false)->assertJsonPath('card.content.company.name', 'Fixture Corp');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function notConfigured(): array
    {
        return [
            'disabled' => [['ai_card.enabled' => false]],
            'empty key' => [['ai_card.api_key' => '']],
            'empty base url' => [['ai_card.base_url' => '']],
            'empty model' => [['ai_card.model' => null]],
            'plain http remote' => [['ai_card.base_url' => 'http://ai.example.test/v1']],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('notConfigured')]
    public function test_not_configured_provider_never_sends_a_request(array $override): void
    {
        $this->enable();
        config()->set($override);
        $this->nvda();
        Http::fake();

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertExactJson(['card' => null, 'available' => false, 'fundamentals' => null]);
        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_company_card_generations', 0);
    }

    public function test_defaults_ship_disabled_and_conservative(): void
    {
        $config = require base_path('config/ai_card.php');

        $this->assertFalse(config('ai_card.enabled'));
        $this->assertFalse(config('ai_card.rights_verified'));
        $this->assertSame(7, $config['freshness_days']);
        $this->assertSame(10, $config['user_daily_limit']);
        $this->assertSame(200, $config['daily_limit']);
        $this->assertSame(5, $config['requests_per_minute']);
        $this->assertSame(40, $config['timeout_seconds']);
        $this->assertSame('json_schema', $config['response_format']);
    }

    private function withTechnicalData(Instrument $instrument): void
    {
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-13', 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000]);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-14', 'open' => 100, 'high' => 103, 'low' => 99, 'close' => 102.5, 'volume' => 1200]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-14', 'rsi14' => 61.25, 'sma50' => 95]);
        Signal::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-14', 'type' => 'golden_cross']);
        ChartPattern::factory()->create(['instrument_id' => $instrument->id, 'type' => 'double_bottom', 'status' => 'confirmed', 'breakout_level' => 101.0]);
    }

    public function test_without_rights_only_identity_leaves_the_server(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $this->withTechnicalData($instrument);
        $this->fakeValid();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.context_mode', 'identity')->assertJsonPath('card.context_as_of_date', null);

        Http::assertSent(function (Request $request): bool {
            $context = json_decode($request['messages'][1]['content'], true);

            return $context === ['instrument' => [
                'ticker' => 'NVDA', 'company' => 'NVIDIA Corporation',
                'sector' => 'Information Technology', 'exchange' => 'NASDAQ',
            ]] && ! str_contains($request->body(), '102.5') && ! str_contains($request->body(), 'golden_cross');
        });
    }

    public function test_with_rights_the_stored_technical_context_is_sent(): void
    {
        $this->enable();
        config()->set('ai_card.rights_verified', true);
        $instrument = $this->nvda();
        $this->withTechnicalData($instrument);
        $this->fakeValid();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('card.context_mode', 'identity+technical')
            ->assertJsonPath('card.context_as_of_date', '2026-10-14');

        Http::assertSent(function (Request $request): bool {
            $context = json_decode($request['messages'][1]['content'], true);
            $technical = $context['technical'] ?? [];

            return $context['instrument']['ticker'] === 'NVDA'
                && $technical['as_of_date'] === '2026-10-14'
                && $technical['close'] == 102.5
                && $technical['change_percent'] == 2.5
                && $technical['indicators']['rsi14'] == 61.25
                && array_keys($technical['indicators']) === ['sma20', 'sma50', 'sma200', 'rsi14', 'rvol', 'macd_hist', 'adx']
                && $technical['signals'] === ['golden_cross']
                && $technical['chart_patterns'] == [['type' => 'double_bottom', 'status' => 'confirmed', 'breakout_level' => 101]];
        });
    }

    public function test_stale_card_is_shown_until_a_successful_regeneration_replaces_it(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $old = AiCompanyCard::factory()->create(['instrument_id' => $instrument->id, 'generated_at' => now()->subDays(8)]);
        $user = User::factory()->create();
        $this->fakeValid();

        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')->assertOk()->assertJsonPath('card.fresh', false);
        Http::assertNothingSent();

        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('reused', false)->assertJsonPath('card.fresh', true);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_company_cards', 1);
        $this->assertTrue($old->fresh()->generated_at->greaterThan(now()->subMinute()));

        // A model change makes the stored card stale even inside the window.
        config()->set('ai_card.model', 'fixture-model-2');
        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')->assertJsonPath('card.fresh', false);
    }

    public function test_user_daily_cap_blocks_new_calls_but_never_reuse(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $other = Instrument::factory()->create(['ticker' => 'AAPL']);
        AiCompanyCard::factory()->create(['instrument_id' => $other->id]);
        $user = User::factory()->create();
        $this->logAttempts($user, $instrument, 10);
        $this->logAttempts($user, $instrument, 5, now()->subDay());
        $this->fakeValid();

        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(429)->assertJsonPath('code', 'user_daily_limit');
        $this->actingAs($user)->postJson('/api/instruments/AAPL/company-card')
            ->assertOk()->assertJsonPath('reused', true);

        Http::assertNothingSent();
        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_global_daily_cap_blocks_every_new_call(): void
    {
        $this->enable();
        config()->set('ai_card.daily_limit', 3);
        $instrument = $this->nvda();
        $this->logAttempts(User::factory()->create(), $instrument, 2);
        $this->logAttempts(null, $instrument, 1);
        $this->fakeValid();

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'daily_limit_reached');
        Http::assertNothingSent();
    }

    public function test_a_concurrent_generation_of_the_same_key_is_reused_without_a_second_call(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $this->fakeValid();
        // While this request waited for the lock, another worker stored the card.
        $this->app->bind(CompanyCardService::class, fn ($app) => new class($app->make(\App\Services\Ai\OpenAiCompatibleClient::class), $app->make(\App\Services\CompanyCard\CompanyCardContextBuilder::class), $instrument) extends CompanyCardService
        {
            public function __construct($client, $builder, private readonly Instrument $instrument)
            {
                parent::__construct($client, $builder);
            }

            protected function withLock(string $key, callable $callback): mixed
            {
                AiCompanyCard::factory()->create(['instrument_id' => $this->instrument->id]);

                return parent::withLock($key, $callback);
            }
        });

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertOk()->assertJsonPath('reused', true);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_company_card_generations', 0);
    }

    public function test_a_held_lock_returns_busy_without_a_provider_call(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        $this->fakeValid();
        $this->app->bind(CompanyCardService::class, fn ($app) => new class($app->make(\App\Services\Ai\OpenAiCompatibleClient::class), $app->make(\App\Services\CompanyCard\CompanyCardContextBuilder::class)) extends CompanyCardService
        {
            protected function lockWaitSeconds(): int
            {
                return 0;
            }
        });
        $lock = Cache::lock('ai-card:'.hash('sha256', $instrument->id.'|es|company-card-v2'), 60);
        $this->assertTrue($lock->get());

        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')
            ->assertStatus(503)->assertJsonPath('code', 'busy');
        $lock->release();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_company_card_generations', 0);
    }

    public function test_generate_route_is_throttled_with_its_own_prefix(): void
    {
        $this->enable();
        $instrument = $this->nvda();
        AiCompanyCard::factory()->create(['instrument_id' => $instrument->id]);
        Http::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')->assertOk();
        }
        $this->actingAs($user)->postJson('/api/instruments/NVDA/company-card')->assertStatus(429);
        // The read route has its own budget.
        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')->assertOk();
    }

    public function test_generation_log_keeps_no_prompt_or_response_text(): void
    {
        $this->enable();
        $this->nvda();
        $this->fakeValid();
        $this->actingAs(User::factory()->create())->postJson('/api/instruments/NVDA/company-card')->assertOk();

        $this->assertSame([
            'id', 'instrument_id', 'user_id', 'locale', 'model', 'schema_version', 'prompt_version',
            'response_format', 'status', 'error_code', 'prompt_tokens', 'completion_tokens',
            'total_tokens', 'latency_ms', 'created_at', 'updated_at',
        ], Schema::getColumnListing('ai_company_card_generations'));
        $row = json_encode(AiCompanyCardGeneration::query()->first()->getAttributes());
        $this->assertStringNotContainsString('Designs chips', $row);
        $this->assertStringNotContainsString('financial data summarizer', $row);
        $this->assertStringNotContainsString('fixture-key', $row);
    }

    public function test_generation_log_is_pruned_after_retention(): void
    {
        config()->set('ai_card.log_retention_days', 90);
        $instrument = $this->nvda();
        $this->logAttempts(null, $instrument, 2, now()->subDays(91));
        $this->logAttempts(null, $instrument, 1, now()->subDays(89));

        $this->artisan('model:prune', ['--model' => [AiCompanyCardGeneration::class]])->assertSuccessful();

        $this->assertDatabaseCount('ai_company_card_generations', 1);
    }

    private function logAttempts(?User $user, Instrument $instrument, int $count, ?\DateTimeInterface $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $row = AiCompanyCardGeneration::query()->create([
                'instrument_id' => $instrument->id, 'user_id' => $user?->id, 'locale' => 'es',
                'model' => 'fixture-model', 'schema_version' => 'company-card-v1',
                'prompt_version' => 'company-card-prompt-v1', 'response_format' => 'json_schema',
                'status' => 'provider_error', 'error_code' => 'provider_unavailable',
            ]);
            if ($at !== null) {
                $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            }
        }
    }
}
