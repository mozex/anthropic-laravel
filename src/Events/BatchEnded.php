<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * A batch's poll job saw it end, after dispatching a `BatchResultReceived` per result and before the `then()`
 * callbacks. `batch['request_counts']` says how many requests succeeded, errored, were canceled or expired.
 */
class BatchEnded
{
    /**
     * @param  array<array-key, mixed>  $batch  the ended batch as the API returned it (`BatchResponse::toArray()`)
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $id,
        public readonly array $batch,
    ) {}
}
