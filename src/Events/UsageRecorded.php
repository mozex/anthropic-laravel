<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * A Messages call was billed: a non-streamed answer, a stream once it ended or was dropped (`completed` false, with the
 * usage it had), or a batch result (`batch`, with its batch ID and `custom_id`). The billable and the feature are what
 * `Anthropic::for()` set for the call, or for the batch when it was sent.
 */
class UsageRecorded
{
    /**
     * @param  array<array-key, mixed>  $usage  the answer's `usage`, as the API sent it
     * @param  int|null  $at  the Unix time the call was billed, so a queued ledger row keeps it
     */
    public function __construct(
        public readonly string $connection,
        public readonly ?string $model,
        public readonly ?string $requestId,
        public readonly array $usage,
        public readonly ?int $durationMs,
        public readonly bool $batch,
        public readonly bool $completed,
        public readonly ?string $billableType,
        public readonly int|string|null $billableId,
        public readonly ?string $feature,
        public readonly ?string $batchId = null,
        public readonly ?string $customId = null,
        public readonly ?int $at = null,
    ) {}
}
