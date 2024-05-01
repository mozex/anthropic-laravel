<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Facades;

use Anthropic\Contracts\ResponseContract;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Responses\Completions\StreamResponse as CompletionsStreamResponse;
use Anthropic\Responses\Messages\StreamResponse as MessagesStreamResponse;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Anthropic\Resources\Messages chat()
 * @method static \Anthropic\Resources\Completions completions()
 */
final class Anthropic extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'anthropic';
    }

    /**
     * @param  array<array-key, ResponseContract|CompletionsStreamResponse|MessagesStreamResponse|string>  $responses
     */
    public static function fake(array $responses = []): AnthropicFake /** @phpstan-ignore-line */
    {
        $fake = new AnthropicFake($responses);
        self::swap($fake);

        return $fake;
    }
}
