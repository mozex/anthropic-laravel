<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Anthropic\Contracts\ResponseContract;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Responses\StreamResponse;

/**
 * @method static \Anthropic\Resources\Assistants assistants()
 * @method static \Anthropic\Resources\Audio audio()
 * @method static \Anthropic\Resources\Chat chat()
 * @method static \Anthropic\Resources\Completions completions()
 * @method static \Anthropic\Resources\Embeddings embeddings()
 * @method static \Anthropic\Resources\Edits edits()
 * @method static \Anthropic\Resources\Files files()
 * @method static \Anthropic\Resources\FineTunes fineTunes()
 * @method static \Anthropic\Resources\Images images()
 * @method static \Anthropic\Resources\Models models()
 * @method static \Anthropic\Resources\Moderations moderations()
 * @method static \Anthropic\Resources\Threads threads()
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
     * @param  array<array-key, ResponseContract|StreamResponse|string>  $responses
     */
    public static function fake(array $responses = []): AnthropicFake /** @phpstan-ignore-line */
    {
        $fake = new AnthropicFake($responses);
        self::swap($fake);

        return $fake;
    }
}
