<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Middleware;

use Anthropic\Laravel\Middleware\Concerns\ReadsMessages;
use Closure;
use Illuminate\Support\Arr;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Records every HTTP attempt as a Telescope client request, tagged `anthropic`, `model:<model>` and `req:<request id>`,
 * without a credential (in a header, the URL or the body) and with Telescope's own hidden headers and parameters
 * masked. Turned on with `http.telescope`; `http.laravel_events` would record each call a second time. The options of
 * Telescope's own client request watcher (`enabled`, `ignore_hosts`, `size_limit`) don't apply: this is its own opt-in.
 */
class RecordsInTelescope
{
    use ReadsMessages;

    /**
     * Telescope's client request watcher purges a JSON answer over this many kilobytes; so does this.
     */
    private const SIZE_LIMIT = 64;

    /**
     * @param  Closure(RequestInterface): ResponseInterface  $next
     */
    public function __invoke(RequestInterface $request, Closure $next): ResponseInterface
    {
        if (! Telescope::isRecording()) {
            return $next($request);
        }

        $public = self::withoutSecrets($request);
        $payload = self::json($public) ?? [];
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : null;
        $entry = [
            'method' => $request->getMethod(),
            'uri' => (string) $public->getUri(),
            'headers' => self::masked(self::lowercased($public), Telescope::$hiddenRequestHeaders),
            'payload' => self::masked($payload, Telescope::$hiddenRequestParameters),
        ];
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Telescope::recordClientRequest(IncomingEntry::make($entry)->tags(self::tags($model, null)));

            throw $exception;
        }

        $requestId = $response->getHeaderLine('request-id');

        Telescope::recordClientRequest(IncomingEntry::make([
            ...$entry,
            'response_status' => $response->getStatusCode(),
            'response_headers' => self::masked(self::lowercased($response->withoutHeader('Set-Cookie')), Telescope::$hiddenRequestHeaders),
            'response' => self::content($response),
            'duration' => self::since($started),
        ])->tags(self::tags($model, $requestId !== '' ? $requestId : null)));

        return $response;
    }

    /**
     * What Telescope shows of the answer, in its watcher's words for what it doesn't show.
     *
     * @return array<array-key, mixed>|string
     */
    private static function content(ResponseInterface $response): array|string
    {
        if (self::isStreamed($response)) {
            return 'Stream Response';
        }

        $json = self::json($response);

        if ($json !== null) {
            return ($response->getBody()->getSize() ?? 0) / 1000 <= self::SIZE_LIMIT
                ? self::masked($json, Telescope::$hiddenResponseParameters)
                : 'Purged By Telescope';
        }

        return $response->getBody()->getSize() === 0 ? 'Empty Response' : 'HTML Response';
    }

    /**
     * @return list<string>
     */
    private static function tags(?string $model, ?string $requestId): array
    {
        return array_values(array_filter(['anthropic', $model === null ? null : 'model:'.$model, $requestId === null ? null : 'req:'.$requestId]));
    }

    /**
     * The message's headers by lowercase name, as Telescope shows them.
     *
     * @return array<string, string>
     */
    private static function lowercased(MessageInterface $message): array
    {
        return array_change_key_case(self::headers($message), CASE_LOWER);
    }

    /**
     * The data with each of Telescope's hidden paths masked, as its own watcher does.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $hidden
     * @return array<array-key, mixed>
     */
    private static function masked(array $data, array $hidden): array
    {
        foreach ($hidden as $path) {
            if (is_string($path) && Arr::get($data, $path)) {
                Arr::set($data, $path, '********');
            }
        }

        return $data;
    }
}
