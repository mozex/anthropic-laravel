<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Ledger;

use Anthropic\Laravel\Events\UsageRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Writes each recorded call to the usage ledger, on the queue so a call never waits on the database. Listening only
 * while `anthropic.ledger.enabled`; a batch's results are written by its poll job, in chunks.
 *
 * @internal
 */
class RecordUsage implements ShouldQueue
{
    /**
     * A batch's poll job writes its results itself, in chunks, so they queue nothing.
     */
    public function shouldQueue(UsageRecorded $usage): bool
    {
        return ! $usage->batch;
    }

    /**
     * Best effort: on the sync queue a failed insert would fail a call the API already answered and billed.
     */
    public function handle(UsageRecorded $usage): void
    {
        try {
            AnthropicRequest::query()->insert(AnthropicRequest::rowFrom($usage));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function viaQueue(): ?string
    {
        $queue = config('anthropic.ledger.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
