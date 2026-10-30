<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Ledger;

use Anthropic\Laravel\Budgets\Budget;
use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Responses\Messages\StreamResponse;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Testing\Fakes\Fake;
use Throwable;

/**
 * The client's stream completion handler: once a stream ends or is dropped, it charges what the stream used to the
 * enforced token budgets and dispatches `UsageRecorded` with it, and with `anthropic.context` adds its tokens to
 * Context, as the middleware does for a non-streamed answer. A stream that read nothing records nothing, and a failure
 * to record never reaches the stream's reader.
 *
 * @internal
 */
class RecordsStreamUsage
{
    /**
     * @param  Closure(): ?Dispatcher  $events  null in a container without one
     * @param  (Closure(): Context)|null  $context  with `anthropic.context` on
     */
    public function __construct(
        private readonly Closure $events,
        private readonly string $connection,
        private readonly ?Closure $context = null,
    ) {}

    /**
     * @param  StreamResponse<CreateStreamedResponse>  $stream
     */
    public function __invoke(StreamResponse $stream): void
    {
        $usage = $stream->usage();

        if ($usage === []) {
            return;
        }

        try {
            $events = ($this->events)();
            $attribution = Attribution::current();
            $listening = $events !== null && ($events instanceof Fake || $events->hasListeners(UsageRecorded::class));

            if ($listening || $attribution['budgets'] !== []) {
                $recorded = new UsageRecorded($this->connection, $stream->model(), $stream->meta()->requestId, $usage, $stream->durationMs(), false, $stream->completed(), ...$attribution, at: Date::now()->getTimestamp());
                Budget::chargeRecorded($recorded);

                if ($listening) {
                    $events->dispatch($recorded);
                }
            }

            if ($this->context !== null) {
                $context = ($this->context)();

                foreach (['input_tokens' => 'anthropic_input_tokens', 'output_tokens' => 'anthropic_output_tokens'] as $field => $key) {
                    if (is_int($usage[$field] ?? null)) {
                        $context->increment($key, $usage[$field]);
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
