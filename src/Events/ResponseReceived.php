<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * An HTTP attempt to the Claude API got its answer, whatever the status. A streamed answer has no body or usage: its
 * events are still to be read.
 */
class ResponseReceived
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>|null  $body  the decoded JSON body, or null for a stream or a body that isn't JSON
     * @param  array<array-key, mixed>|null  $usage  the body's `usage`
     * @param  array<array-key, mixed>  $meta  the request ID and rate limits, as `meta()->toArray()` gives them
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $method,
        public readonly string $uri,
        public readonly int $status,
        public readonly array $headers,
        public readonly ?array $body,
        public readonly ?array $usage,
        public readonly array $meta,
        public readonly int $durationMs,
    ) {}
}
