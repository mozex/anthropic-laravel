<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Resources;

use Anthropic\Contracts\Resources\ModelsContract;
use Anthropic\Responses\Meta\MetaInformation;
use Anthropic\Responses\Models\ListResponse;
use Anthropic\Responses\Models\RetrieveResponse;
use Anthropic\Testing\Resources\ModelsTestResource;
use Generator;
use Illuminate\Support\Facades\Cache;

/**
 * A connection's Models resource with `cached()`: the PHP client's resource gets every other call, a fake's resource
 * assertions included.
 *
 * @mixin \Anthropic\Resources\Models
 */
class Models implements ModelsContract
{
    public function __construct(
        private readonly ModelsContract $models,
        private readonly string $cacheKey,
    ) {}

    /**
     * Every model the connection's key can use, kept with `Cache::flexible()`: fresh for `$ttl[0]` seconds, then
     * served while it's refreshed after the response, until `$ttl[1]`. Under `Anthropic::fake()`, never cached.
     *
     * @param  array{0: int, 1: int}  $ttl
     * @return list<RetrieveResponse>
     */
    public function cached(array $ttl = [3600, 86400]): array
    {
        if ($this->models instanceof ModelsTestResource) {
            return iterator_to_array($this->models->iterate(), false);
        }

        // Arrays, never the DTOs, so a cached list outlives an upgrade that changes them. The refresh lock expires, so a
        // process killed mid-refresh doesn't hold the list stale until it's dropped.
        /** @var list<array{id: string, type: string, created_at: string, display_name: string}> $models */
        $models = Cache::flexible($this->cacheKey, $ttl, fn (): array => array_map(
            fn (RetrieveResponse $model): array => $model->toArray(),
            iterator_to_array($this->models->iterate(), false),
        ), ['seconds' => 60]);

        return array_map(fn (array $model): RetrieveResponse => RetrieveResponse::from($model, MetaInformation::from([])), $models);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function list(array $parameters = []): ListResponse
    {
        return $this->models->list($parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return Generator<int, RetrieveResponse, mixed, void>
     */
    public function iterate(array $parameters = []): Generator
    {
        return $this->models->iterate($parameters);
    }

    public function retrieve(string $model): RetrieveResponse
    {
        return $this->models->retrieve($model);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->models->{$method}(...$parameters);
    }
}
