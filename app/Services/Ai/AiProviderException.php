<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A failed structured completion. `reason` is `provider_unavailable`
 * (transport, timeout, non-2xx) or `invalid_provider_response` (unusable
 * answer). Usage and format are kept so the caller can log the attempt; the
 * exception never carries the prompt, the response text or the key.
 */
class AiProviderException extends RuntimeException
{
    /**
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}  $usage
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $responseFormat,
        public readonly array $usage = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null],
        public readonly ?int $latencyMs = null,
    ) {
        parent::__construct($reason);
    }
}
