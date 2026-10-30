<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Facades;

use Anthropic\Contracts\ClientContract;
use Anthropic\Contracts\ResponseContract;
use Anthropic\Contracts\ResponseStreamContract;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Responses\Completions\StreamResponse as CompletionsStreamResponse;
use Anthropic\Responses\Messages\StreamResponse as MessagesStreamResponse;
use Anthropic\Responses\Raw\RawResponse;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Stringable;
use Throwable;

/**
 * @method static \Anthropic\Resources\Completions completions()
 * @method static \Anthropic\Resources\Messages messages()
 * @method static \Anthropic\Resources\Models models()
 * @method static \Anthropic\Resources\Batches batches()
 * @method static \Anthropic\Resources\Files files()
 * @method static \Anthropic\Contracts\ClientContract withOptions(array<string, mixed> $options)
 * @method static \Anthropic\Responses\Raw\RawResponse request(string $method, string $path, array<string, mixed> $parameters = [])
 * @method static \Anthropic\Contracts\ClientContract connection(?string $name = null)
 * @method static \Anthropic\Contracts\ClientContract withApiKey(string $apiKey)
 * @method static \Anthropic\Contracts\ClientContract organization()
 * @method static \Anthropic\Contracts\ClientContract enterprise()
 * @method static \Anthropic\Contracts\ClientContract compliance()
 * @method static \Anthropic\Laravel\AnthropicManager extend(string $name, \Closure $callback)
 * @method static \Anthropic\Laravel\AnthropicManager forgetConnection(?string $name = null)
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
}
