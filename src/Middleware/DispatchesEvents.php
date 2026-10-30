<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Middleware;

use Anthropic\Exceptions\ConnectionException;
use Anthropic\Exceptions\TimeoutException;
use Anthropic\Laravel\Events\RateLimited;
use Anthropic\Laravel\Events\RequestFailed;
use Anthropic\Laravel\Events\RequestSending;
use Anthropic\Laravel\Events\ResponseReceived;
use Anthropic\Laravel\Middleware\Concerns\ReadsMessages;
use Anthropic\Responses\Meta\MetaInformation;
use Closure;
use Exception;
use Http\Discovery\Psr17Factory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed as LaravelConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending as LaravelRequestSending;
use Illuminate\Http\Client\Events\ResponseReceived as LaravelResponseReceived;
use Illuminate\Http\Client\Request as LaravelRequest;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Testing\Fakes\Fake;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Dispatches the package's events around every HTTP attempt, a retry included: `RequestSending`, then
 * `ResponseReceived`, or `RequestFailed` (and `RateLimited` on a 429). With `$laravelEvents`, it also dispatches
 * Laravel's own `RequestSending`, then `ResponseReceived` or `ConnectionFailed`, always as a pair, which Telescope
 * records. No event carries the API key, a token or another credential, in a header or the body (an MCP server's
 * `authorization_token`), and only JSON bodies are read. An event nobody listens to isn't built.
 */
class DispatchesEvents
{
    use ReadsMessages;

    /**
     * @param  Closure(): Dispatcher  $events  resolved on each attempt, so an `Event::fake()` set later still applies
     */
    public function __construct(
        private readonly Closure $events,
        private readonly string $connection = 'default',
        private readonly bool $laravelEvents = false,
    ) {}

    /**
     * @param  Closure(RequestInterface): ResponseInterface  $next
     */
    public function __invoke(RequestInterface $request, Closure $next): ResponseInterface
    {
        $events = ($this->events)();
        $listening = [];

        foreach ([RequestSending::class, ResponseReceived::class, RequestFailed::class, RateLimited::class] as $event) {
            if ($events instanceof Fake || $events->hasListeners($event)) {
                $listening[$event] = true;
            }
        }

        if ($listening === [] && ! $this->laravelEvents) {
            return $next($request);
        }

        $method = $request->getMethod();
        $uri = (string) $request->getUri()->withUserInfo('')->withQuery('');
        // Built only when something reads it: the package's RequestSending, or Laravel's events.
        $public = isset($listening[RequestSending::class]) || $this->laravelEvents ? self::withoutSecrets($request) : $request;
        $started = hrtime(true);

        if (isset($listening[RequestSending::class])) {
            $events->dispatch(new RequestSending($this->connection, $method, $uri, self::headers($public), self::json($public)));
        }

        if ($this->laravelEvents) {
            $events->dispatch(new LaravelRequestSending(new LaravelRequest($public)));
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // Any failure, a stray request under Http::preventStrayRequests() or an app middleware's included, so
            // Laravel's RequestSending is always paired.
            if (isset($listening[RequestFailed::class])) {
                $errorType = match (true) {
                    $exception instanceof TimeoutException => 'client_timeout_error',
                    $exception instanceof ConnectionException => 'connection_error',
                    default => 'transport_error',
                };

                $events->dispatch(new RequestFailed($this->connection, $method, $uri, null, $errorType, $exception->getMessage(), null, self::since($started)));
            }

            if ($this->laravelEvents) {
                $events->dispatch(new LaravelConnectionFailed(new LaravelRequest($public), new LaravelConnectionException($exception->getMessage(), 0, $exception instanceof Exception ? $exception : null)));
            }

            throw $exception;
        }

        $duration = self::since($started);
        $status = $response->getStatusCode();

        // An error answer is buffered, so its body can be read here and by the client after.
        if ($status >= 400 && ! $response->getBody()->isSeekable()) {
            $response = $response->withBody((new Psr17Factory)->createStream((string) $response->getBody()));
        }

        $body = self::isStreamed($response) ? null : self::json($response);
        $meta = MetaInformation::from($response->getHeaders());

        if (isset($listening[ResponseReceived::class])) {
            $events->dispatch(new ResponseReceived(
                $this->connection,
                $method,
                $uri,
                $status,
                self::headers($response->withoutHeader('Set-Cookie')),
                $body,
                is_array($body['usage'] ?? null) ? $body['usage'] : null,
                $meta->toArray(),
                $duration,
            ));
        }

        if ($status >= 400) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];

            if (isset($listening[RequestFailed::class])) {
                $events->dispatch(new RequestFailed(
                    $this->connection,
                    $method,
                    $uri,
                    $status,
                    is_string($error['type'] ?? null) ? $error['type'] : null,
                    is_string($error['message'] ?? null) ? $error['message'] : "The API answered with a {$status}.",
                    self::requestId($response, $body),
                    $duration,
                ));
            }

            if ($status === 429 && isset($listening[RateLimited::class])) {
                $details = is_array($error['details'] ?? null) ? $error['details'] : [];
                $errorCode = $details['error_code'] ?? null;

                $events->dispatch(new RateLimited($this->connection, $method, $uri, $meta->retryAfter, is_string($errorCode) ? $errorCode : null, $meta->toArray()));
            }
        }

        if ($this->laravelEvents) {
            // A stream's too, as Laravel's own client sends it: a listener that reads the body drains the stream.
            $events->dispatch(new LaravelResponseReceived(new LaravelRequest($public), new LaravelResponse($response)));
        }

        return $response;
    }
}
