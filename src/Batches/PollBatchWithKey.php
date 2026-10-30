<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Batches;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * The poll job of a batch sent through `Anthropic::withApiKey()`: its sealed key is encrypted on its own, and the queue
 * stores the whole job encrypted too, callbacks included.
 */
class PollBatchWithKey extends PollBatch implements ShouldBeEncrypted {}
