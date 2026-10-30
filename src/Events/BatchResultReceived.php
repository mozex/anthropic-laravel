<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * One request's result in an ended batch, as its poll job reads them: `result['type']` is `succeeded` (with the
 * `message`), `errored` (with the `error`), `canceled` or `expired`. A queued listener handles each in its own job.
 */
class BatchResultReceived
{
    /**
     * @param  array<array-key, mixed>  $result  the result as the API sent it (`BatchResult::toArray()`)
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $batchId,
        public readonly string $customId,
        public readonly array $result,
    ) {}
}
