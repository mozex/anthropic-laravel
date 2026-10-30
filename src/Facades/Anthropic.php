<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Facades;

use Anthropic\Contracts\ClientContract;
use Anthropic\Contracts\ResponseContract;
use Anthropic\Contracts\ResponseStreamContract;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\ClientBuilder;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Responses\Completions\StreamResponse as CompletionsStreamResponse;
use Anthropic\Responses\Messages\StreamResponse as MessagesStreamResponse;
use Anthropic\Responses\Raw\RawResponse;
use Anthropic\Testing\Recorder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use SensitiveParameter;
use Stringable;
use Throwable;

/**
 * @method static \Anthropic\Resources\Completions completions()
 * @method static \Anthropic\Resources\Messages messages()
 * @method static \Anthropic\Laravel\Resources\Models models()
 * @method static \Anthropic\Resources\Batches batches()
 * @method static \Anthropic\Resources\Files files()
 * @method static \Anthropic\Laravel\Connection withOptions(array<string, mixed> $options)
 * @method static \Anthropic\Responses\Raw\RawResponse request(string $method, string $path, array<string, mixed> $parameters = [])
 * @method static \Anthropic\Laravel\Batches\PendingBatch batch(array<int, array<string, mixed>> $requests)
 * @method static \Anthropic\Laravel\Connection connection(?string $name = null)
 * @method static \Anthropic\Laravel\Connection withApiKey(string $apiKey)
 * @method static \Anthropic\Laravel\AnthropicManager for(?\Illuminate\Database\Eloquent\Model $billable, ?string $feature = null)
 * @method static \Anthropic\Contracts\ClientContract organization()
 * @method static \Anthropic\Contracts\ClientContract enterprise()
 * @method static \Anthropic\Contracts\ClientContract compliance()
 * @method static \Anthropic\Laravel\AnthropicManager extend(string $name, \Closure $callback)
 * @method static \Anthropic\Laravel\AnthropicManager forgetConnection(?string $name = null)
 * @method static void assertSent(string $resource, callable|int|null $callback = null)
 * @method static void assertSentTimes(string $resource, int $times, ?callable $callback = null)
 * @method static void assertSentInOrder(array<int, string|array{0: string, 1: string}|\Closure> $expected)
 * @method static void assertNotSent(string $resource, callable|int|null $callback = null)
 * @method static void assertNothingSent()
 * @method static void assertBatched(callable|int|null $callback = null)
 * @method static void assertNotBatched(?callable $callback = null)
 * @method static void assertSentWithOptions(string $resource, array<string, mixed> $options)
 * @method static void assertSentOn(string $connection, string $resource, callable|int|null $callback = null)
 * @method static void assertNotSentOn(string $connection, string $resource, ?callable $callback = null)
 * @method static array<int, \Anthropic\Testing\Requests\TestRequest> sent(?string $resource = null, ?callable $callback = null)
 * @method static \Anthropic\Laravel\Testing\AnthropicFake respondTo(string|array{0: string, 1: string}|\Closure $match, mixed $response)
 * @method static \Anthropic\Laravel\Testing\AnthropicFake preventStrayRequests(bool $prevent = true)
 * @method static void addResponses(array<array-key, mixed> $responses)
 *
 * @see AnthropicManager
 */
class Anthropic extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AnthropicManager::class;
    }

    /**
     * Forwards a call to the manager as Laravel's facade does, with the arguments kept out of a trace: `withApiKey()`
     * and `withOptions()` can carry a key, and Flare collects a trace's arguments. Laravel's body rather than a call to
     * `parent::__callStatic()`, whose frame would hold the arguments unmarked.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     */
    public static function __callStatic($method, #[SensitiveParameter] $args): mixed
    {
        $instance = static::getFacadeRoot();

        if (! is_object($instance)) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->{$method}(...$args);
    }

    /**
     * Swaps the client for a fake: every connection of the manager answers from it, and so does anything the container
     * resolves as a ClientContract. The facade's root stays the manager, so macros and a type-hinted manager reach it.
     *
     * @param  array<array-key, ResponseContract<array<array-key, mixed>>|ResponseStreamContract<mixed>|CompletionsStreamResponse|MessagesStreamResponse|RawResponse|Throwable|Stringable|string>  $responses
     */
    public static function fake(array $responses = []): AnthropicFake
    {
        $fake = new AnthropicFake($responses);

        // Load the deferred provider first, or loading it later would bind the real client over the fake.
        if (static::$app instanceof Application) {
            static::$app->loadDeferredProvider(ClientContract::class);
        }

        static::$app?->instance(ClientContract::class, $fake);

        // swap() binds the root's name to the fake, so a second fake() in a container without the provider finds a fake.
        $manager = static::$app?->bound(AnthropicManager::class) === true ? static::$app->make(AnthropicManager::class) : null;

        if ($manager instanceof AnthropicManager) {
            $manager->useFake($fake);
            static::clearResolvedInstance(AnthropicManager::class);
        } else {
            // A container without the provider, as some tests build: the fake stands in for the root itself.
            self::swap($fake);
        }

        return $fake;
    }

    /**
     * Records the default connection's calls (the facade's and an injected ClientContract's) to a cassette file once,
     * then replays them with no HTTP call: the client is rebuilt from the config with the recorder as its innermost
     * middleware, so the events, the fallback and your client middleware run as they would, on the recorded answers.
     * It records while the file is missing or `$record` is true, and a replay needs no key. Pass an absolute path:
     * `base_path()` in an app, `__DIR__` in a package's tests. It takes over from an `Anthropic::fake()` made before.
     */
    public static function record(string $path, ?bool $record = null): Recorder
    {
        $app = static::$app;

        if (! $app instanceof Application) {
            throw new RuntimeException('Anthropic::record() needs the Laravel application.');
        }

        $app->loadDeferredProvider(ClientContract::class);

        $recorder = Recorder::cassette($path, $record);
        $config = $app->make('config')->get('anthropic', []);
        $config = is_array($config) ? $config : [];
        unset($config['connections']);

        // One class name alone counts as a list, as it does in ClientBuilder.
        $middleware = $config['middleware'] ?? [];

        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        $config['middleware'] = [...(is_array($middleware) ? $middleware : []), $recorder];
        $config = ClientBuilder::withLaravelAi($config);

        if (! ClientBuilder::hasCredential($config)) {
            // Recording needs the API; a replay sends nothing, so it needs no key of yours.
            if ($recorder->recording()) {
                throw new RuntimeException("No cassette at [{$path}] to replay, and no key to record one with: set ANTHROPIC_API_KEY and run the test once.");
            }

            $config['api_key'] = 'replaying';
        }

        $client = ClientBuilder::make($app, $config, AnthropicManager::DEFAULT);
        $app->instance(ClientContract::class, $client);

        $manager = $app->bound(AnthropicManager::class) ? $app->make(AnthropicManager::class) : null;

        if ($manager instanceof AnthropicManager) {
            $manager->useFake(null);
            $manager->forgetConnection(AnthropicManager::DEFAULT);
            static::clearResolvedInstance(AnthropicManager::class);
        } else {
            // A container without the provider, where fake() made the fake the root: the recording client takes its place.
            self::swap($client);
        }

        return $recorder;
    }
}
