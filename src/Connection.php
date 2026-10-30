<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Contracts\Resources\BatchesContract;
use Anthropic\Contracts\Resources\CompletionsContract;
use Anthropic\Contracts\Resources\FilesContract;
use Anthropic\Contracts\Resources\MessagesContract;
use Anthropic\Contracts\Resources\ModelsContract;
use Anthropic\Laravel\Batches\PendingBatch;
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

    public function messages(): MessagesContract
    {
        return $this->client->messages();
    }

    public function models(): ModelsContract
    {
        return $this->client->models();
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
