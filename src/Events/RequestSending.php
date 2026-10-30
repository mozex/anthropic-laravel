<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * An HTTP attempt to the Claude API is about to go out. Every value is a scalar or an array, so a queued listener
 * can take it; the API key, a bearer token and any header named like a credential are left out. Only a JSON body is
 * decoded, never an upload.
 */
class RequestSending
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>|null  $body  the decoded JSON body, or null without one
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly ?array $body,
    ) {}
}
