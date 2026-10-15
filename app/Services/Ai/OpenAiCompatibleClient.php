<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic client for an OpenAI-compatible Chat Completions API that must
 * answer with one JSON object following a caller-supplied JSON Schema.
 *
 * It has no knowledge of any product feature: connection settings come from a
 * config block (default `config/ai_card.php`) and the caller validates the
 * decoded data. Logs carry only the exception class or HTTP status, never the
 * key, the prompt or the response content.
 */
class OpenAiCompatibleClient
{
    public function __construct(private readonly string $configKey = 'ai_card') {}

    /**
     * Base URL, key and model are set and the URL is https:// (or http:// on
     * localhost / 127.0.0.1 for local LLM servers).
     */
    public function isConfigured(): bool
    {
        $key = $this->setting('api_key');
        $model = $this->setting('model');

        return is_string($key) && trim($key) !== ''
            && is_string($model) && trim($model) !== ''
            && $this->endpoint() !== null;
    }

    public function model(): ?string
    {
        $model = $this->setting('model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     *
     * @throws AiProviderException
     */
    public function structured(array $messages, string $schemaName, array $schema): StructuredCompletion
    {
        $endpoint = $this->endpoint();
        $model = $this->model();
        $key = $this->setting('api_key');
        if ($endpoint === null || $model === null || ! is_string($key) || trim($key) === '') {
            throw new AiProviderException('provider_unavailable', 'none');
        }

        $format = $this->setting('response_format') === 'json_object' ? 'json_object' : 'json_schema';
        $started = hrtime(true);

        $response = $this->send($endpoint, trim($key), $this->body($model, $messages, $format, $schemaName, $schema), $format, $started);

        if ($format === 'json_schema' && $response->status() === 400 && (bool) $this->setting('response_format_fallback')) {
            // Some "OpenAI-compatible" servers reject json_schema; retry once
            // with json_object and the schema embedded in the system prompt.
            Log::info('AI provider rejected json_schema; retrying with json_object');
            $format = 'json_object';
            $response = $this->send($endpoint, trim($key), $this->body($model, $messages, $format, $schemaName, $schema), $format, $started);
        }

        $latency = $this->elapsedMs($started);
        if (! $response->successful()) {
            Log::warning('AI provider rejected the request', ['status' => $response->status()]);
            throw new AiProviderException('provider_unavailable', $format, $this->usage(null), $latency);
        }

        $payload = $response->json();
        $usage = $this->usage(is_array($payload) ? $payload : null);
        $data = is_array($payload) ? $this->decodeChoice($payload) : null;
        if ($data === null) {
            throw new AiProviderException('invalid_provider_response', $format, $usage, $latency);
        }

        return new StructuredCompletion($data, $usage, $format, $latency);
    }

    /**
     * Schema embedded in the system prompt for the json_object mode.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function schemaInstruction(string $schemaName, array $schema): string
    {
        return "Respond with exactly one JSON object (no Markdown, no prose) that validates against this JSON Schema named \"{$schemaName}\". Every property is required and no other property is allowed:\n"
            .json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $endpoint, string $key, array $body, string $format, int $started): Response
    {
        $timeout = max(1, (int) $this->setting('timeout_seconds'));

        try {
            return Http::withToken($key)->acceptJson()->asJson()
                ->withoutRedirecting()
                ->connectTimeout(min(10, $timeout))
                ->timeout($timeout)
                ->post($endpoint, $body);
        } catch (Throwable $exception) {
            Log::warning('AI provider transport failed', ['type' => get_class($exception)]);
            throw new AiProviderException('provider_unavailable', $format, $this->usage(null), $this->elapsedMs($started));
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function body(string $model, array $messages, string $format, string $schemaName, array $schema): array
    {
        if ($format === 'json_object') {
            $instruction = self::schemaInstruction($schemaName, $schema);
            if (($messages[0]['role'] ?? null) === 'system') {
                $messages[0]['content'] .= "\n\n".$instruction;
            } else {
                array_unshift($messages, ['role' => 'system', 'content' => $instruction]);
            }
            $responseFormat = ['type' => 'json_object'];
        } else {
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
            ];
        }

        $body = [
            'model' => $model,
            'messages' => array_values($messages),
            'response_format' => $responseFormat,
        ];
        $param = $this->setting('max_tokens_param') === 'max_completion_tokens' ? 'max_completion_tokens' : 'max_tokens';
        $body[$param] = max(1, (int) $this->setting('max_tokens'));
        $temperature = $this->setting('temperature');
        if (is_int($temperature) || is_float($temperature)) {
            $body['temperature'] = (float) $temperature;
        }

        return $body;
    }

    /**
     * The first choice decoded to a JSON object, or null when the answer was
     * truncated, refused, empty or not JSON.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>|null
     */
    private function decodeChoice(array $payload): ?array
    {
        $choice = $payload['choices'][0] ?? null;
        if (! is_array($choice) || ($choice['finish_reason'] ?? null) !== 'stop') {
            return null;
        }

        $message = $choice['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }
        $refusal = $message['refusal'] ?? null;
        if ($refusal !== null && $refusal !== '') {
            return null;
        }
        $content = $message['content'] ?? null;
        if (! is_string($content)) {
            return null;
        }

        $content = trim($content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $content, $match) === 1) {
            $content = $match[1];
        }

        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /**
     * @param  array<mixed>|null  $payload
     * @return array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}
     */
    private function usage(?array $payload): array
    {
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $read = fn (string $name): ?int => is_int($usage[$name] ?? null) && $usage[$name] >= 0 ? $usage[$name] : null;

        return [
            'prompt_tokens' => $read('prompt_tokens'),
            'completion_tokens' => $read('completion_tokens'),
            'total_tokens' => $read('total_tokens'),
        ];
    }

    private function endpoint(): ?string
    {
        $base = $this->setting('base_url');
        if (! is_string($base) || trim($base) === '') {
            return null;
        }
        $base = rtrim(trim($base), '/');
        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            return null;
        }

        return $base.'/chat/completions';
    }

    private function setting(string $name): mixed
    {
        return config($this->configKey.'.'.$name);
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
