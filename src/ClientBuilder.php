<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic\Client;
use Anthropic\Factory;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Laravel\Middleware\AddsToContext;
use Anthropic\Laravel\Middleware\DispatchesEvents;
use Anthropic\Laravel\Middleware\RecordsInTelescope;
use Anthropic\Middleware\RefusalFallbackMiddleware;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Context\Repository as Context;
use InvalidArgumentException;
use Laravel\Nightwatch\Core as Nightwatch;
use Laravel\Telescope\Telescope;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * Builds a client from one connection's settings: the top-level keys of `config/anthropic.php` for the default
 * connection, a named connection's merged over them for another.
 *
 * @internal
 */
class ClientBuilder
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function make(Container $app, #[SensitiveParameter] array $config, string $connection = 'default'): Client
    {
        $apiKey = $config['api_key'] ?? null;
        $authToken = self::nonBlankString($config['auth_token'] ?? null);
        $baseUri = self::nonBlankString($config['base_uri'] ?? null);

        if (! is_string($apiKey) && $authToken === null) {
            throw ApiKeyIsMissing::create();
        }

        return (new Factory)->withConfig([
            'api_key' => $apiKey,
            'auth_token' => $authToken,
            'workspace_id' => $config['workspace_id'] ?? null,
            'api_version' => $config['api_version'] ?? null,
            // laravel/ai's URL (withLaravelAi()) is the full API root, /v1 included; ANTHROPIC_BASE_URL leaves it out.
            'base_uri' => $baseUri,
            'base_url' => $baseUri === null ? ($config['base_url'] ?? null) : null,
            'betas' => self::betas($config['beta'] ?? []),
            'max_retries' => $config['max_retries'] ?? null,
            'middleware' => self::middleware($app, $config, $connection),
            'http_client' => self::httpClient($app, $config),
            // Applied last, so a header set here wins over the ones the keys above produce.
            'headers' => $config['headers'] ?? [],
        ])->make();
    }

    /**
     * The default connection's settings with laravel/ai's Anthropic key and URL, as a pair, when this package has no
     * credential of its own; the settings as they are otherwise. Only the default connection's settings go through
     * it, so a named connection without a key never takes the app's.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    public static function withLaravelAi(array $config): array
    {
        if (self::hasCredential($config)) {
            return $config;
        }

        $laravelAi = config('ai.providers.anthropic');

        if (! is_array($laravelAi) || self::nonBlankString($laravelAi['key'] ?? null) === null) {
            return $config;
        }

        return [...$config, 'api_key' => $laravelAi['key'], 'base_uri' => self::nonBlankString($laravelAi['url'] ?? null)];
    }

    /**
     * The request timeout in seconds: `request_timeout`, or 30 when it's missing, blank (an empty
     * `ANTHROPIC_REQUEST_TIMEOUT=` line) or not a number. 0 is Guzzle's "no timeout".
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function requestTimeout(array $config): float
    {
        return is_numeric($config['request_timeout'] ?? null) ? (float) $config['request_timeout'] : 30.0;
    }

    /**
     * The `fallback_models` list, trimmed, without blank entries.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public static function fallbackModels(array $config): array
    {
        $models = $config['fallback_models'] ?? [];

        // A config written by hand as env('ANTHROPIC_FALLBACK_MODELS') gives the comma-separated string itself.
        if (is_string($models)) {
            $models = explode(',', $models);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $model): string => is_string($model) ? trim($model) : '',
            is_array($models) ? $models : [],
        )));
    }

    /**
     * Whether the settings carry a non-blank key or token. An empty `ANTHROPIC_API_KEY=` line, as `anthropic:install`
     * writes one, is no credential.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function hasCredential(array $config): bool
    {
        return self::nonBlankString($config['api_key'] ?? null) !== null || self::nonBlankString($config['auth_token'] ?? null) !== null;
    }

    /**
     * The client's middleware: the refusal fallback, then the events (so each fallback attempt is an event of its
     * own), Context (with `context`) and Telescope (with `http.telescope`), then the connection's own `middleware`,
     * class names the container resolves or callables.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<callable>
     */
    private static function middleware(Container $app, #[SensitiveParameter] array $config, string $connection): array
    {
        $middleware = [];
        $fallbackModels = self::fallbackModels($config);

        if ($fallbackModels !== []) {
            // No shared FallbackState: a worker's singleton would pin every user to a fallback after one refusal.
            $middleware[] = new RefusalFallbackMiddleware($fallbackModels);
        }

        $http = is_array($config['http'] ?? null) ? $config['http'] : [];

        if ($app->bound(Dispatcher::class)) {
            $middleware[] = new DispatchesEvents(static fn (): Dispatcher => $app->make(Dispatcher::class), $connection, ($http['laravel_events'] ?? null) === true);
        }

        // Inside the fallback, like the events, so each attempt and each fallback hop is recorded on its own.
        if (($config['context'] ?? null) === true) {
            $middleware[] = new AddsToContext(static fn (): Context => $app->make(Context::class));
        }

        if (($http['telescope'] ?? null) === true && class_exists(Telescope::class)) {
            $middleware[] = new RecordsInTelescope;
        }

        return [
            ...$middleware,
            ...self::resolveMiddleware($app, $config['middleware'] ?? [], 'Each anthropic.middleware entry must be a client middleware: an invokable class name or a callable.'),
        ];
    }

    /**
     * The Guzzle client, with the connection's timeouts and `http` settings.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function httpClient(Container $app, #[SensitiveParameter] array $config): GuzzleClient
    {
        $options = ['timeout' => self::requestTimeout($config)];
        $connectTimeout = $config['connect_timeout'] ?? null;

        if (is_numeric($connectTimeout) && (float) $connectTimeout > 0) {
            $options['connect_timeout'] = (float) $connectTimeout;
        }

        $http = is_array($config['http'] ?? null) ? $config['http'] : [];
        $laravelClient = ($http['laravel_client'] ?? null) === true;
        $stack = HandlerStack::create();

        foreach (self::resolveMiddleware($app, $http['middleware'] ?? [], 'Each anthropic.http.middleware entry must be a Guzzle middleware: an invokable class name or a callable.') as $middleware) {
            $stack->push($middleware);
        }

        // Nightwatch records Laravel's HTTP client through its global middleware, which laravel_client already runs,
        // so it's attached here only without it. Its Core is bound once its provider is registered, enabled or not.
        // Resolved on each attempt, as Laravel's own client does: Guzzle keeps the stack it resolved, and Nightwatch
        // decides there whether it's paused by Nightwatch::ignore().
        if (! $laravelClient && ($http['nightwatch'] ?? true) !== false && class_exists(Nightwatch::class)
            && $app->bound(Nightwatch::class) && config('nightwatch.enabled', true) !== false) {
            $stack->unshift(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($app, $handler) {
                $wrapped = $app->make(Nightwatch::class)->guzzleMiddleware()($handler);

                return is_callable($wrapped) ? $wrapped($request, $options) : $handler($request, $options);
            });
        }

        if ($laravelClient) {
            // Innermost, and built on each attempt from the HTTP factory of that moment, so an Http::fake() or
            // Http::preventStrayRequests() set after the client was built still applies, and the app's own
            // middleware runs before the fake answers. Laravel's own events come from DispatchesEvents, in pairs.
            $stack->push(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($app, $handler) {
                $http = $app->make(HttpFactory::class);
                $pending = $http->createPendingRequest();
                $laravel = new HandlerStack($handler);

                foreach ($http->getGlobalMiddleware() as $global) {
                    if (is_callable($global)) {
                        $laravel->push($global);
                    }
                }

                $laravel->push($pending->buildRecorderHandler());
                $laravel->push($pending->buildStubHandler());

                return $laravel($request, $options);
            });
        }

        $options['handler'] = $stack;

        return new GuzzleClient($options);
    }

    /**
     * The `beta` list without the entries that aren't strings, which the package has always skipped (the client's
     * `withConfig()` throws on them).
     */
    private static function betas(mixed $beta): mixed
    {
        return is_array($beta) ? array_filter($beta, is_string(...)) : $beta;
    }

    /**
     * A config list of middleware, class names resolved from the container; one class name alone counts as a list.
     * An entry that resolves to no callable throws the given message.
     *
     * @return list<callable>
     */
    private static function resolveMiddleware(Container $app, mixed $entries, string $error): array
    {
        if (is_string($entries)) {
            $entries = [$entries];
        }

        $middleware = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $resolved = is_string($entry) ? $app->make($entry) : $entry;

            if (! is_callable($resolved)) {
                throw new InvalidArgumentException($error);
            }

            $middleware[] = $resolved;
        }

        return $middleware;
    }

    /**
     * A config value that is a non-blank string, trimmed, or null.
     */
    private static function nonBlankString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
