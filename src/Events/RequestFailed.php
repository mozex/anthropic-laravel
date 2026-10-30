<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * An HTTP attempt to the Claude API failed: an error status, or no answer at all (a connection failure or a timeout,
 * whose status is null). The client may retry it; the exception the caller sees comes after the last attempt.
 */
class RequestFailed
{
    /**
     * @param  string|null  $errorType  the API's error type (`overloaded_error` ...), or `connection_error`, `client_timeout_error` or `transport_error` without an answer
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $method,
        public readonly string $uri,
        public readonly ?int $status,
        public readonly ?string $errorType,
        public readonly string $message,
        public readonly ?string $requestId,
        public readonly int $durationMs,
    ) {}
}
