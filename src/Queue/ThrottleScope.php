<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Queue;

/**
 * The key of the throttled job calling now, with a budget, so `RecordsBudget` knows where an answer's rate limit goes.
 * An instance in the container, set around the call and restored after it: Context would carry it into the jobs it
 * dispatches.
 *
 * @internal
 */
class ThrottleScope
{
    public ?string $key = null;
}
