<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Testing;

use Anthropic\Resources\Batches;
use Anthropic\Testing\ClientFake;
use Anthropic\Testing\Requests\TestRequest;
use PHPUnit\Framework\Assert as PHPUnit;

class AnthropicFake extends ClientFake
{
    /**
     * The container binds the fake itself as the client, so calls through an injected ClientContract are the default
     * connection's, and `assertSentOn('default', ...)` counts them.
     */
    protected ?string $connection = 'default';

    /**
     * Asserts a Message Batch was created, through `Anthropic::batch()` or `batches()->create()`, on any connection:
     * the callback gets its requests and the connection's name. With an int, that exactly that many were.
     *
     * @param  (callable(array<array-key, mixed>, string): bool)|int|null  $callback
     */
    public function assertBatched(callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            $count = count($this->batched());

            PHPUnit::assertSame($callback, $count, "{$count} batches were created instead of {$callback}.");

            return;
        }

        PHPUnit::assertNotEmpty($this->batched($callback), 'The expected batch was not created.');
    }

    /**
     * Asserts no Message Batch was created, or none the callback accepts.
     *
     * @param  (callable(array<array-key, mixed>, string): bool)|null  $callback
     */
    public function assertNotBatched(?callable $callback = null): void
    {
        PHPUnit::assertEmpty($this->batched($callback), 'An unexpected batch was created.');
    }

    /**
     * The batches created, those the callback accepts with a callback.
     *
     * @param  (callable(array<array-key, mixed>, string): bool)|null  $callback
     * @return list<TestRequest>
     */
    private function batched(?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->sent(Batches::class, fn (string $method): bool => $method === 'create'),
            function (TestRequest $request) use ($callback): bool {
                $parameters = array_values($request->args())[0] ?? [];
                $requests = is_array($parameters) && is_array($parameters['requests'] ?? null) ? $parameters['requests'] : [];

                return $callback === null || $callback($requests, $request->connection() ?? 'default');
            },
        ));
    }
}
