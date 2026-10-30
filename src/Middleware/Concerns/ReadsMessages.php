<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Middleware\Concerns;

use Http\Discovery\Psr17Factory;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What the middleware that record a request share, so no credential leaves them: not in a header, the URL or the body.
 *
 * @internal
 */
trait ReadsMessages
{
    /**
     * The request headers never recorded: the credentials, and any header whose name says it holds one, such as a
     * gateway's `X-Gateway-Key`.
     */
    private const SECRET_HEADER = '/(key|token|secret|auth|cookie|password)/i';

    /**
     * The body field that holds a credential, wherever it sits: the MCP connector's `mcp_servers[].authorization_token`,
     * a batch's requests included.
     */
    private const SECRET_FIELD = 'authorization_token';

    /**
     * The request without its credential headers, its URL without user info or a query string, where a gateway key may
     * ride, and a JSON body without its credential fields.
     */
    private static function withoutSecrets(RequestInterface $request): RequestInterface
    {
        $request = $request->withUri($request->getUri()->withUserInfo('')->withQuery(''), true);

        foreach (array_keys($request->getHeaders()) as $name) {
            if (preg_match(self::SECRET_HEADER, (string) $name) === 1) {
                $request = $request->withoutHeader((string) $name);
            }
        }

        $stream = $request->getBody();

        // Only a JSON body is read, so an upload never is.
        if (! self::isJson($request) || ! $stream->isSeekable()) {
            return $request;
        }

        $raw = (string) $stream;
        $stream->rewind();

        // Decoded only when the field is there, so a large body isn't decoded twice on every attempt.
        if (! str_contains($raw, '"'.self::SECRET_FIELD.'"')) {
            return $request;
        }

        $body = self::json($request);

        if ($body !== null && ($redacted = self::redacted($body)) !== $body) {
            $json = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $request = $request->withBody((new Psr17Factory)->createStream($json));
        }

        return $request;
    }

    /**
     * The body with every credential field masked, at any depth.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    private static function redacted(array $body): array
    {
        foreach ($body as $key => $value) {
            if ($key === self::SECRET_FIELD && $value !== null) {
                $body[$key] = '********';
            } elseif (is_array($value)) {
                $body[$key] = self::redacted($value);
            }
        }

        return $body;
    }

    /**
     * The message's headers, one line each.
     *
     * @return array<string, string>
     */
    private static function headers(MessageInterface $message): array
    {
        $headers = [];

        foreach ($message->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * The message's body as an array when it's JSON by its media type, its stream rewound for whoever reads it next;
     * null otherwise, so an upload or a download is never read into memory.
     *
     * @return array<array-key, mixed>|null
     */
    private static function json(MessageInterface $message): ?array
    {
        $stream = $message->getBody();

        if (! self::isJson($message) || ! $stream->isSeekable()) {
            return null;
        }

        $stream->rewind();
        $decoded = json_decode($stream->getContents(), true);
        $stream->rewind();

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The answer's request ID: its `request-id` header, else the `request_id` an error body carries.
     *
     * @param  array<array-key, mixed>|null  $body
     */
    private static function requestId(ResponseInterface $response, ?array $body): ?string
    {
        $requestId = $response->getHeaderLine('request-id') !== '' ? $response->getHeaderLine('request-id') : ($body['request_id'] ?? null);

        return is_string($requestId) ? $requestId : null;
    }

    /**
     * Whether the message is JSON by its media type.
     */
    private static function isJson(MessageInterface $message): bool
    {
        $mediaType = strtolower(trim(explode(';', $message->getHeaderLine('Content-Type'))[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * Whether the answer is a stream, or any body that can't be read twice, which is left for the caller.
     */
    private static function isStreamed(MessageInterface $message): bool
    {
        return ! $message->getBody()->isSeekable() || str_contains(strtolower($message->getHeaderLine('Content-Type')), 'event-stream');
    }

    private static function since(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
