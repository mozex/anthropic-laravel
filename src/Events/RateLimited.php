<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * An HTTP attempt to the Claude API got a 429, after its `RequestFailed`. A spend-cap 429 carries the error code
 * `enforced_spend_limit_reached` and no `retry-after`.
 */
class RateLimited
{
    /**
     * @param  int|null  $retryAfter  the seconds `retry-after` asks for, when it's sent
     * @param  array<array-key, mixed>  $meta  the request ID and rate limits, as `meta()->toArray()` gives them
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $method,
        public readonly string $uri,
        public readonly ?int $retryAfter,
        public readonly ?string $errorCode,
        public readonly array $meta,
    ) {}
}
