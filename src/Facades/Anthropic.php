<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Facades;

use Anthropic\Contracts\ClientContract;
use Anthropic\Contracts\ResponseContract;
use Anthropic\Contracts\ResponseStreamContract;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Responses\Completions\StreamResponse as CompletionsStreamResponse;
use Anthropic\Responses\Messages\StreamResponse as MessagesStreamResponse;
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
 */
class Anthropic extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'anthropic';
    }

    /**
     * Swaps the client for a fake, for the facade and for anything the container resolves as a ClientContract.
     *
     * @param  array<array-key, ResponseContract<array<array-key, mixed>>|ResponseStreamContract<mixed>|CompletionsStreamResponse|MessagesStreamResponse|Throwable|Stringable|string>  $responses
     */
    public static function fake(array $responses = []): AnthropicFake
    {
        $fake = new AnthropicFake($responses);

        // Load the deferred provider first, or loading it later would bind the real client over the fake.
        if (static::$app instanceof Application) {
            static::$app->loadDeferredProvider(ClientContract::class);
        }

        static::$app?->instance(ClientContract::class, $fake);
        self::swap($fake);

        return $fake;
    }
}
