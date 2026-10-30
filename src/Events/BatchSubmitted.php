<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * `Anthropic::batch(...)->dispatch()` created a Message Batch; its poll job is queued next.
 */
class BatchSubmitted
{
    /**
     * @param  array<array-key, mixed>  $batch  the batch as the API returned it (`BatchResponse::toArray()`)
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $id,
        public readonly array $batch,
    ) {}
}
