<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A call under an enforced token budget was refused before it was sent: the budget is used up until its window resets.
 * Like Laravel's own `ThrottleRequestsException`, it renders as a 429 with `Retry-After` and isn't reported; the
 * message leaves the key out, since a JSON error answer shows it.
 */
class BudgetExceeded extends TooManyRequestsHttpException
{
    public function __construct(
        string $message,
        public readonly string $key = '',
        public readonly int $tokens = 0,
        public readonly int $availableIn = 0,
    ) {
        parent::__construct(max(1, $availableIn), $message);
    }

    public static function for(string $key, int $tokens, int $availableIn): self
    {
        return new self("The token budget of {$tokens} tokens is used up; it resets in {$availableIn} seconds.", $key, $tokens, $availableIn);
    }
}
