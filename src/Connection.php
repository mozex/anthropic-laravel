<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Contracts\Resources\BatchesContract;
use Anthropic\Contracts\Resources\CompletionsContract;
use Anthropic\Contracts\Resources\FilesContract;
use Anthropic\Laravel\Batches\PendingBatch;
use Anthropic\Laravel\Resources\Messages;
use Anthropic\Laravel\Resources\Models;
use Anthropic\Responses\Raw\RawResponse;
use SensitiveParameter;

/**
 * A connection's client in Laravel: the PHP client, which gets every call, plus what Laravel adds, such as `batch()`.
 * `client()` returns the PHP client itself; a method only it has, such as `settings()`, is passed on to it.
 *
 * @mixin Client
 */
class Connection implements ClientContract
{
    /**
     * @param  list<array<string, mixed>>  $options  each `withOptions()` call's options, in order
     */
    public function __construct(
        private readonly ClientContract $client,
        public readonly string $name,
        #[SensitiveParameter] private readonly ?string $apiKey = null,
        #[SensitiveParameter] private readonly array $options = [],
    ) {}

    /**
     * The PHP client this connection hands its calls to: the container's `ClientContract` for the default
     * connection, or the fake's view of the connection under `Anthropic::fake()`.
     */
    public function client(): ClientContract
    {
        return $this->client;
    }

    /**
     * The key `Anthropic::withApiKey()` built this connection with, which a batch's poll job needs to poll with it.
     *
     * @internal
     */
    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * The options of each `withOptions()` call this connection came from, which a batch's poll job sends again.
     *
     * @internal
     *
     * @return list<array<string, mixed>>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @deprecated The Text Completions API is deprecated. Use messages() instead.
     */
    public function completions(): CompletionsContract
    {
        return $this->client->completions();
    }

    /**
     * The Messages resource, whose streams have Laravel's responses (`toEventStream()` and the others).
     */
    public function messages(): Messages
    {
        return new Messages($this->client->messages());
    }

    /**
     * The Models resource with `cached()`, whose cache is this connection's (and a `withApiKey()` key's) own.
     */
    public function models(): Models
    {
        $cacheKey = "anthropic:models:{$this->name}";

        if ($this->apiKey !== null) {
            // An HMAC under the app key, so a cache key Telescope records says nothing about the API key.
            $appKey = config('app.key');
            $cacheKey .= ':'.hash_hmac('sha256', $this->apiKey, is_string($appKey) ? $appKey : '');
        }

        return new Models($this->client->models(), $cacheKey);
    }

    public function batches(): BatchesContract
    {
        return $this->client->batches();
    }

    public function files(): FilesContract
    {
        return $this->client->files();
    }

    /**
     * A connection whose requests also carry these options, keeping this one's name and key, so `batch()` and the
     * rest still work on it, and a batch's poll job polls with the same options. This connection is left unchanged.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOptions(#[SensitiveParameter] array $options): self
    {
        return new self($this->client->withOptions($options), $this->name, $this->apiKey, [...$this->options, $options]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function request(string $method, string $path, array $parameters = []): RawResponse
    {
        return $this->client->request($method, $path, $parameters);
    }

    /**
     * A Message Batch of these requests, each `['custom_id' => ..., 'params' => [...]]` as the API takes them, sent
     * through this connection when you call `dispatch()` and then polled by a queued job until it ends.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    public function batch(array $requests): PendingBatch
    {
        return new PendingBatch($this, $requests);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, #[SensitiveParameter] array $parameters): mixed
    {
        return $this->client->{$method}(...$parameters);
    }
}
