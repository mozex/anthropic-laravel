<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Events;

/**
 * A job under `ThrottlesAnthropic` hit the organization's monthly spend cap (a 429 with `enforced_spend_limit_reached`)
 * and was failed: calls fail until the cap resets or is raised in the Console, so retrying wouldn't help.
 */
class SpendCapReached
{
    /**
     * @param  string  $key  the throttle key the job passed
     * @param  string  $job  the job's class
     */
    public function __construct(
        public readonly string $key,
        public readonly string $job,
        public readonly ?string $requestId,
        public readonly string $message,
    ) {}
}
