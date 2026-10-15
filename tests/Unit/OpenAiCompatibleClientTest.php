<?php

namespace Tests\Unit;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Generic OpenAI-compatible structured-output client. Offline: `Http::fake`.
 */
class OpenAiCompatibleClientTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['answer'],
        'properties' => ['answer' => ['type' => 'string']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai_card.base_url', 'https://llm.example.test/v1/');
        config()->set('ai_card.api_key', 'secret-fixture-key');
        config()->set('ai_card.model', 'fixture-model');
        config()->set('ai_card.response_format', 'json_schema');
        config()->set('ai_card.response_format_fallback', true);
        config()->set('ai_card.max_tokens', 500);
        config()->set('ai_card.max_tokens_param', 'max_tokens');
        config()->set('ai_card.temperature', 0.2);
    }

    /** @return array<string, mixed> */
    private function answer(string $content, string $finish = 'stop', ?string $refusal = null, bool $usage = true): array
    {
        return [
            'choices' => [['finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content, 'refusal' => $refusal]]],
        ] + ($usage ? ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]] : []);
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => '{"x":1}']];
    }

    public function test_sends_strict_json_schema_and_decodes_the_answer(): void
    {
        Http::fake(['llm.example.test/*' => Http::response($this->answer('{"answer":"ok"}'))]);

        $result = (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);

        $this->assertSame(['answer' => 'ok'], $result->data);
        $this->assertSame('json_schema', $result->responseFormat);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $result->usage);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer secret-fixture-key')
            && $request->data() === [
                'model' => 'fixture-model',
                'messages' => $this->messages(),
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'demo', 'strict' => true, 'schema' => self::SCHEMA]],
                'max_tokens' => 500,
                'temperature' => 0.2,
            ]);
    }

    public function test_max_completion_tokens_and_no_temperature_are_configurable(): void
    {
        config()->set('ai_card.max_tokens_param', 'max_completion_tokens');
        config()->set('ai_card.temperature', null);
        Http::fake(['llm.example.test/*' => Http::response($this->answer('{"answer":"ok"}', usage: false))]);

        $result = (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);

        $this->assertSame(['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null], $result->usage);
        Http::assertSent(fn (Request $request): bool => $request['max_completion_tokens'] === 500
            && ! array_key_exists('max_tokens', $request->data())
            && ! array_key_exists('temperature', $request->data()));
    }

    public function test_strips_a_markdown_json_fence(): void
    {
        Http::fake(['llm.example.test/*' => Http::response($this->answer("```json\n{\"answer\":\"fenced\"}\n```"))]);

        $this->assertSame(['answer' => 'fenced'], (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA)->data);
    }

    public function test_falls_back_once_to_json_object_with_the_schema_in_the_system_prompt(): void
    {
        Http::fake(['llm.example.test/*' => Http::sequence()
            ->push(['error' => 'json_schema unsupported'], 400)
            ->push($this->answer('{"answer":"ok"}'))]);

        $result = (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);

        $this->assertSame('json_object', $result->responseFormat);
        Http::assertSentCount(2);
        $second = Http::recorded()[1][0];
        $this->assertSame(['type' => 'json_object'], $second['response_format']);
        $this->assertStringStartsWith('Be brief.', $second['messages'][0]['content']);
        $this->assertStringContainsString('"additionalProperties":false', $second['messages'][0]['content']);
        $this->assertSame('{"x":1}', $second['messages'][1]['content']);
    }

    public function test_a_second_400_is_not_retried_again(): void
    {
        Http::fake(['llm.example.test/*' => Http::response(['error' => 'bad'], 400)]);

        try {
            (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);
            $this->fail('expected exception');
        } catch (AiProviderException $exception) {
            $this->assertSame('provider_unavailable', $exception->reason);
            $this->assertSame('json_object', $exception->responseFormat);
        }
        Http::assertSentCount(2);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unusableAnswers(): array
    {
        return [
            'truncated' => [['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"answer":"o']]]]],
            'content filter' => [['choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => '{}']]]]],
            'refusal' => [['choices' => [['finish_reason' => 'stop', 'message' => ['content' => null, 'refusal' => 'No.']]]]],
            'not json' => [['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Here you go']]]]],
            'json list' => [['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '[1,2]']]]]],
            'no choices' => [['choices' => []]],
            'tool call' => [['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => null]]]]],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('unusableAnswers')]
    public function test_unusable_answers_are_invalid_provider_response(array $payload): void
    {
        Http::fake(['llm.example.test/*' => Http::response($payload + ['usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7]])]);

        try {
            (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);
            $this->fail('expected exception');
        } catch (AiProviderException $exception) {
            $this->assertSame('invalid_provider_response', $exception->reason);
            $this->assertSame(7, $exception->usage['total_tokens']);
        }
    }

    public function test_transport_and_server_errors_are_logged_without_key_prompt_or_content(): void
    {
        Log::spy();
        Http::fake(['llm.example.test/*' => Http::sequence()
            ->pushResponse(Http::response(['error' => 'Be brief.'], 503))
            ->whenEmpty(fn () => throw new ConnectionException('timed out'))]);

        foreach ([1, 2] as $attempt) {
            try {
                (new OpenAiCompatibleClient)->structured($this->messages(), 'demo', self::SCHEMA);
                $this->fail('expected exception');
            } catch (AiProviderException $exception) {
                $this->assertSame('provider_unavailable', $exception->reason);
            }
        }

        Log::shouldHaveReceived('warning')->with('AI provider rejected the request', ['status' => 503])->once();
        Log::shouldHaveReceived('warning')->with('AI provider transport failed', ['type' => ConnectionException::class])->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (...$args): bool => ! str_contains(json_encode($args), 'secret-fixture-key')
            && ! str_contains(json_encode($args), 'Be brief'))->twice();
    }

    /** @return array<string, array{0: string|null, 1: bool}> */
    public static function baseUrls(): array
    {
        return [
            'https' => ['https://api.example.test/v1', true],
            'local http' => ['http://127.0.0.1:8199/v1', true],
            'localhost http' => ['http://localhost:11434/v1', true],
            'remote http' => ['http://api.example.test/v1', false],
            'credentials in url' => ['https://user:pass@api.example.test/v1', false],
            'not a url' => ['api.example.test', false],
            'empty' => ['', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('baseUrls')]
    public function test_only_safe_base_urls_count_as_configured(?string $url, bool $configured): void
    {
        config()->set('ai_card.base_url', $url);

        $this->assertSame($configured, (new OpenAiCompatibleClient)->isConfigured());
    }

    public function test_missing_key_or_model_is_not_configured(): void
    {
        config()->set('ai_card.api_key', ' ');
        $this->assertFalse((new OpenAiCompatibleClient)->isConfigured());
        config()->set('ai_card.api_key', 'k');
        config()->set('ai_card.model', '');
        $this->assertFalse((new OpenAiCompatibleClient)->isConfigured());
    }
}
