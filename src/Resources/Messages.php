<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Resources;

use Anthropic\Contracts\Resources\MessagesContract;
use Anthropic\Laravel\Responses\StreamResponse;
use Anthropic\Responses\Messages\CountTokensResponse;
use Anthropic\Responses\Messages\CreateResponse;

/**
 * A connection's Messages resource whose streams have Laravel's responses (`toEventStream()` and the others): the PHP
 * client's resource gets every call, a fake's resource assertions included.
 *
 * @mixin \Anthropic\Resources\Messages
 */
class Messages implements MessagesContract
{
    public function __construct(private readonly MessagesContract $messages) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function create(array $parameters): CreateResponse
    {
        return $this->messages->create($parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function createStreamed(array $parameters): StreamResponse
    {
        return $this->messages->createStreamed($parameters)->as(StreamResponse::class);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function countTokens(array $parameters): CountTokensResponse
    {
        return $this->messages->countTokens($parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function fits(array $parameters, int $limit): bool
    {
        return $this->messages->fits($parameters, $limit);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function trimToFit(array $parameters, int $limit): array
    {
        return $this->messages->trimToFit($parameters, $limit);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->messages->{$method}(...$parameters);
    }
}
