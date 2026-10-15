<?php

namespace App\Services\Ai;

/**
 * A decoded JSON answer from an OpenAI-compatible provider. `data` is not yet
 * validated against the caller's schema; the caller must validate it.
 */
final class StructuredCompletion
{
    /**
     * @param  array<mixed>  $data
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}  $usage
     */
    public function __construct(
        public readonly array $data,
        public readonly array $usage,
        public readonly string $responseFormat,
        public readonly int $latencyMs,
    ) {}
}
