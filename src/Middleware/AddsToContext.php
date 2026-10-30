<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Middleware;

use Anthropic\Laravel\Middleware\Concerns\ReadsMessages;
use Closure;
use Illuminate\Log\Context\Repository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adds every answer's request ID to Laravel's Context (`anthropic_request_ids`, the last 20), and a non-streamed
 * Messages answer's token counts (`anthropic_input_tokens`, `anthropic_output_tokens`, as the API reports them, cache
 * tokens apart), so the log lines and queued jobs after a call carry them. A retry and a fallback hop are answers of
 * their own. Turned on with `anthropic.context`.
 */
class AddsToContext
{
    use ReadsMessages;

    /**
     * How many request IDs Context keeps: every log line carries them, and every job dispatched after, so a job that
     * calls the API thousands of times mustn't grow them without end.
     */
    private const KEPT_REQUEST_IDS = 20;

    /**
     * @param  Closure(): Repository  $context  resolved on each attempt: Context belongs to the request or job of that
     *                                          moment, and the client outlives them
     */
    public function __construct(private readonly Closure $context) {}

    /**
     * @param  Closure(RequestInterface): ResponseInterface  $next
     */
    public function __invoke(RequestInterface $request, Closure $next): ResponseInterface
    {
        $response = $next($request);
        $context = ($this->context)();
        $body = self::isStreamed($response) ? null : self::json($response);
        $requestId = self::requestId($response, $body);

        if ($requestId !== null && $requestId !== '') {
            $kept = $context->get('anthropic_request_ids', []);
            $context->add('anthropic_request_ids', array_slice([...(is_array($kept) ? array_values($kept) : []), $requestId], -self::KEPT_REQUEST_IDS));
        }

        // Only a Messages answer's usage is what the call consumed.
        $usage = $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/messages') && is_array($body['usage'] ?? null) ? $body['usage'] : [];

        foreach (['input_tokens' => 'anthropic_input_tokens', 'output_tokens' => 'anthropic_output_tokens'] as $field => $key) {
            if (is_int($usage[$field] ?? null)) {
                $context->increment($key, $usage[$field]);
            }
        }

        return $response;
    }
}
