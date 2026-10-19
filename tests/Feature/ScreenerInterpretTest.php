<?php

namespace Tests\Feature;

use App\Models\AiScreenerQuery;
use App\Models\User;
use App\Services\Screener\Ai\ScreenerQueryInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Natural-language Screener API (docs/specs/ai-screener-natural-language.md,
 * scenarios 1-5). Every provider interaction is `Http::fake`; no real AI API
 * is ever called.
 */
class ScreenerInterpretTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://ai.example.test/v1';

    private const ENDPOINT = '/api/screener/interpret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept-Language', 'es');
        $this->enable();
    }

    private function enable(): void
    {
        config()->set('ai_screener.enabled', true);
        config()->set('ai_screener.base_url', self::URL);
        config()->set('ai_screener.api_key', 'fixture-key');
        config()->set('ai_screener.model', 'fixture-model');
        config()->set('ai_screener.response_format', 'json_schema');
        config()->set('ai_screener.response_format_fallback', true);
    }

    /**
     * A schema-valid answer, optionally overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answer(array $overrides = []): array
    {
        return array_merge([
            'signal' => [],
            'rsi_min' => null,
            'rsi_max' => 30,
            'min_rvol' => 1.5,
            'price_above_sma200' => true,
            'ma_cross' => null,
            'pattern' => [],
            'pattern_status' => 'any',
            'sort' => 'rvol_desc',
            'unsupported' => [],
            'explanation' => 'RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5.',
        ], $overrides);
    }

    /**
     * An OpenAI-style Chat Completions answer.
     *
     * @param  array<string, mixed>|string  $content
     * @return array<string, mixed>
     */
    private function completion(array|string $content): array
    {
        return [
            'id' => 'chatcmpl-fixture',
            'object' => 'chat.completion',
            'model' => 'fixture-model',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => is_string($content) ? $content : json_encode($content),
                    'refusal' => null,
                ],
            ]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 80, 'total_tokens' => 980],
        ];
    }

    /** @param  array<string, mixed>|string  $content */
    private function fakeProvider(array|string $content): void
    {
        Http::fake([self::URL.'/chat/completions' => Http::response($this->completion($content))]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedRows(int $count, ?User $user, string $outcome = AiScreenerQuery::OUTCOME_OK, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiScreenerQuery::query()->create(array_merge([
                'user_id' => $user?->id,
                'locale' => 'es',
                'query_length' => 20,
                'outcome' => $outcome,
                'unsupported_count' => 0,
                'model' => 'fixture-model',
                'schema_version' => 'screener-query-v1',
                'prompt_version' => 'screener-query-prompt-v1',
            ], $overrides));
        }
    }

    public function test_scenario_1_a_spanish_request_becomes_screener_filters(): void
    {
        $this->fakeProvider($this->answer());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas por encima de la SMA200 con volumen alto', 'locale' => 'es'])
            ->assertOk()
            ->assertExactJson([
                'filters' => [
                    'signal' => [],
                    'rsi_min' => null,
                    'rsi_max' => 30.0,
                    'min_rvol' => 1.5,
                    'price_above_sma200' => true,
                    'ma_cross' => null,
                    'pattern' => [],
                    'pattern_status' => 'any',
                    'sort' => 'rvol_desc',
                ],
                'unsupported' => [],
                'explanation' => 'RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5.',
            ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::URL.'/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer fixture-key')
                && $body['model'] === 'fixture-model'
                && $body['temperature'] === 0.0
                && $body['max_tokens'] === 800
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['name'] === 'screener_query_v1'
                && $body['response_format']['json_schema']['strict'] === true
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][0]['content'], 'sobrevendidas')
                && $body['messages'][1] === ['role' => 'user', 'content' => 'sobrevendidas por encima de la SMA200 con volumen alto'];
        });

        $row = AiScreenerQuery::query()->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('ok', $row->outcome);
        $this->assertSame('es', $row->locale);
        $this->assertSame(54, $row->query_length);
        $this->assertSame(900, $row->prompt_tokens);
        $this->assertSame(80, $row->completion_tokens);
        $this->assertSame('fixture-model', $row->model);
        $this->assertSame('screener-query-v1', $row->schema_version);
        $this->assertSame('screener-query-prompt-v1', $row->prompt_version);
    }

    public function test_the_locale_defaults_to_the_request_language(): void
    {
        $this->fakeProvider($this->answer(['explanation' => 'RSI at most 30.']));
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Accept-Language', 'en')
            ->postJson(self::ENDPOINT, ['query' => 'oversold stocks'])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->data()['messages'][0]['content'], 'You translate a stock screening request'));
        $this->assertSame('en', AiScreenerQuery::query()->sole()->locale);
    }

    public function test_scenario_2_unsupported_parts_are_returned_and_only_supported_filters_applied(): void
    {
        $this->fakeProvider($this->answer([
            'rsi_max' => null,
            'min_rvol' => null,
            'price_above_sma200' => false,
            'signal' => ['golden_cross', 'golden_cross'],
            'unsupported' => ["  sector\ttecnológico\u{200B} ", 'precio menor de 50 $', ''],
            'explanation' => "Cruce dorado.\nNada más.",
        ]));
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'tecnológicas con cruce dorado por debajo de 50 dólares'])
            ->assertOk()
            ->assertJsonPath('filters.signal', ['golden_cross'])
            ->assertJsonPath('filters.rsi_max', null)
            ->assertJsonPath('filters.price_above_sma200', false)
            ->assertJsonPath('unsupported', ['sector tecnológico', 'precio menor de 50 $'])
            ->assertJsonPath('explanation', 'Cruce dorado. Nada más.');

        $this->assertSame(2, AiScreenerQuery::query()->sole()->unsupported_count);
    }

    /** @return array<string, array{0: array<string, mixed>|string}> */
    public static function invalidAnswerProvider(): array
    {
        return [
            'unknown signal' => [['signal' => ['moon_shot']]],
            'rsi above 100' => [['rsi_max' => 150]],
            'negative rvol' => [['min_rvol' => -1]],
            'rvol above 20' => [['min_rvol' => 25]],
            'numeric string' => [['rsi_min' => '30']],
            'extra key' => [['sector' => 'Technology']],
            'missing key' => [['__drop' => 'sort']],
            'boolean as string' => [['price_above_sma200' => 'true']],
            'unknown ma_cross' => [['ma_cross' => 'sideways']],
            'unknown pattern' => [['pattern' => ['head_and_shoulders']]],
            'too many patterns' => [['pattern' => ['double_top', 'double_bottom', 'cup_with_handle', 'bull_flag', 'bull_flag']]],
            'unknown sort' => [['sort' => 'price_desc']],
            'unknown pattern status' => [['pattern_status' => 'broken']],
            'too many unsupported' => [['unsupported' => ['a', 'b', 'c', 'd', 'e', 'f']]],
            'unsupported too long' => [['unsupported' => [str_repeat('x', 121)]]],
            'empty explanation' => [['explanation' => '   ']],
            'explanation too long' => [['explanation' => str_repeat('y', 301)]],
            'not json' => ['RSI below 30 please'],
        ];
    }

    /** @param  array<string, mixed>|string  $override */
    #[DataProvider('invalidAnswerProvider')]
    public function test_scenario_3_invalid_ai_output_is_rejected_as_a_whole(array|string $override): void
    {
        if (is_array($override)) {
            $content = $this->answer($override);
            if (isset($override['__drop'])) {
                unset($content[$override['__drop']], $content['__drop']);
            }
        } else {
            $content = $override;
        }
        $this->fakeProvider($content);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas con volumen alto'])
            ->assertStatus(503)
            ->assertExactJson([
                'code' => 'invalid_provider_response',
                'message' => __('ai_screener.invalid_provider_response', [], 'es'),
            ]);

        $this->assertSame('invalid', AiScreenerQuery::query()->sole()->outcome);
        // An invalid answer is never cached: the next identical request calls the provider again.
        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas con volumen alto'])->assertStatus(503);
        Http::assertSentCount(2);
    }

    public function test_a_provider_failure_is_provider_unavailable_and_not_counted(): void
    {
        Http::fake([self::URL.'/chat/completions' => Http::response(['error' => 'down'], 500)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable');

        $this->assertSame('unavailable', AiScreenerQuery::query()->sole()->outcome);
    }

    public function test_a_transport_failure_is_provider_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable');
    }

    public function test_scenario_4_the_31st_request_in_24_hours_hits_the_user_cap(): void
    {
        $this->fakeProvider($this->answer());
        $user = User::factory()->create();
        $this->seedRows(20, $user, AiScreenerQuery::OUTCOME_OK);
        $this->seedRows(10, $user, AiScreenerQuery::OUTCOME_INVALID);
        // Not counted: cached, unavailable and limited rows, another user's
        // rows and rows older than 24 h.
        $this->seedRows(5, $user, AiScreenerQuery::OUTCOME_CACHED);
        $this->seedRows(5, $user, AiScreenerQuery::OUTCOME_UNAVAILABLE);
        $this->seedRows(5, $user, AiScreenerQuery::OUTCOME_LIMITED);
        $this->seedRows(3, User::factory()->create());

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'user_daily_limit');
        Http::assertNothingSent();
        $this->assertSame(6, AiScreenerQuery::query()->where('outcome', 'limited')->count());

        AiScreenerQuery::query()->where('user_id', $user->id)->where('outcome', 'ok')->limit(1)
            ->update(['created_at' => now()->subHours(25)]);
        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])->assertOk();
    }

    public function test_scenario_4_the_global_cap_returns_daily_limit_reached(): void
    {
        config()->set('ai_screener.daily_limit', 4);
        $this->fakeProvider($this->answer());
        $this->seedRows(4, User::factory()->create());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'daily_limit_reached');
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function notConfiguredProvider(): array
    {
        return [
            'flag off' => ['ai_screener.enabled', false],
            'missing key' => ['ai_screener.api_key', null],
            'missing model' => ['ai_screener.model', ''],
            'plain http remote url' => ['ai_screener.base_url', 'http://ai.example.test/v1'],
        ];
    }

    #[DataProvider('notConfiguredProvider')]
    public function test_scenario_4_without_configuration_it_is_provider_not_configured(string $key, mixed $value): void
    {
        config()->set($key, $value);
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_not_configured');
        Http::assertNothingSent();
        $this->assertSame(0, AiScreenerQuery::query()->count());
    }

    public function test_the_ai_card_flag_does_not_enable_the_screener(): void
    {
        config()->set('ai_screener.enabled', false);
        config()->set('ai_card.enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])
            ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    }

    public function test_scenario_4_a_repeated_query_is_served_from_cache_without_a_provider_call(): void
    {
        $this->fakeProvider($this->answer());
        $user = User::factory()->create();
        $other = User::factory()->create();

        $first = $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'Sobrevendidas  con volumen alto'])->assertOk()->json();
        // Same request after case/whitespace normalization, from another user.
        $second = $this->actingAs($other)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas con   volumen ALTO'])->assertOk()->json();

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        $this->assertSame(['ok', 'cached'], AiScreenerQuery::query()->orderBy('id')->pluck('outcome')->all());

        // A cache hit does not count against the caps.
        config()->set('ai_screener.user_daily_limit', 0);
        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas con volumen alto'])->assertOk();
        Http::assertSentCount(1);

        // Another locale is another cache entry.
        config()->set('ai_screener.user_daily_limit', 30);
        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas con volumen alto', 'locale' => 'en'])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_version_bump_invalidates_the_cache(): void
    {
        $this->assertNotSame(
            ScreenerQueryInterpreter::cacheKey('sobrevendidas', 'es'),
            (function (): string {
                config()->set('ai_screener.prompt_version', 'screener-query-prompt-v2');

                return ScreenerQueryInterpreter::cacheKey('sobrevendidas', 'es');
            })(),
        );
    }

    public function test_scenario_5_a_visitor_gets_401(): void
    {
        Http::fake();

        $this->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertSame(0, AiScreenerQuery::query()->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInputProvider(): array
    {
        return [
            'missing query' => [[], 'query'],
            'too short' => [['query' => 'ab'], 'query'],
            'too short after trimming' => [['query' => '  ab  '], 'query'],
            'too long' => [['query' => str_repeat('a', 301)], 'query'],
            'not a string' => [['query' => ['rsi']], 'query'],
            'unknown locale' => [['query' => 'sobrevendidas', 'locale' => 'fr'], 'locale'],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('invalidInputProvider')]
    public function test_scenario_5_invalid_input_is_422(array $body, string $field): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(self::ENDPOINT, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        Http::assertNothingSent();
    }

    public function test_a_300_character_query_is_accepted(): void
    {
        $this->fakeProvider($this->answer());
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => str_repeat('á', 300)])->assertOk();
        $this->assertSame(300, AiScreenerQuery::query()->sole()->query_length);
    }

    public function test_the_endpoint_is_throttled_per_user(): void
    {
        config()->set('ai_screener.enabled', false);
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])->assertStatus(503);
        }
        $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => 'sobrevendidas'])->assertStatus(429);
    }

    public function test_scenario_5_the_query_text_is_never_stored_logged_or_used_as_a_readable_cache_key(): void
    {
        config()->set('cache.default', 'database');
        $marker = 'ZQX-marker-'.bin2hex(random_bytes(6));
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $user = User::factory()->create();

        // ok, cached, invalid and unavailable paths all see the marker.
        Http::fakeSequence(self::URL.'/chat/completions')
            ->push($this->completion($this->answer()))
            ->push($this->completion($this->answer(['signal' => ['moon']])))
            ->push(['error' => 'down'], 500);
        foreach (["sobrevendidas {$marker}", "sobrevendidas {$marker}", "otra {$marker}", "tercera {$marker}"] as $query) {
            $this->actingAs($user)->postJson(self::ENDPOINT, ['query' => $query]);
        }
        Http::assertSentCount(3);
        $this->assertSame(['ok', 'cached', 'invalid', 'unavailable'], AiScreenerQuery::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertNotEmpty($logged);

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString($marker, json_encode($row) ?: '', "table {$table}");
                $this->assertStringNotContainsString(strtolower($marker), strtolower(json_encode($row) ?: ''), "table {$table}");
            }
        }
        $this->assertGreaterThan(0, DB::table('cache')->count());
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($marker, $line);
        }
    }

    public function test_old_rows_are_pruned(): void
    {
        $this->seedRows(1, null, AiScreenerQuery::OUTCOME_OK);
        AiScreenerQuery::query()->update(['created_at' => now()->subDays(91)]);
        $this->seedRows(1, null, AiScreenerQuery::OUTCOME_OK);

        $this->artisan('model:prune', ['--model' => [AiScreenerQuery::class]])->assertSuccessful();

        $this->assertSame(1, AiScreenerQuery::query()->count());
    }

    public function test_the_log_table_has_no_column_for_the_query_text(): void
    {
        $this->assertSame([
            'id', 'user_id', 'locale', 'query_length', 'outcome', 'unsupported_count', 'prompt_tokens',
            'completion_tokens', 'latency_ms', 'model', 'schema_version', 'prompt_version', 'created_at',
        ], Schema::getColumnListing('ai_screener_queries'));
    }
}
