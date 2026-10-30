<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Http;

use Anthropic\Laravel\Budgets\Budget;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `anthropic.budget:{key},{tokens},{period},{counts}` route middleware: `anthropic.budget:user,200000,day` holds
 * each user (by their ID, or by IP when signed out) to 200,000 tokens a day. A used-up budget answers 429 with
 * `Retry-After`, at the door or partway through; otherwise it's enforced for the request's calls, beside any other.
 * A key other than `user` and `ip` is used as it is; the count is `billed` (the default) or `rate`.
 */
class TokenBudget
{
    public function handle(Request $request, Closure $next, string $key = 'user', string $tokens = '100000', string $period = 'day', string $counts = 'billed'): Response
    {
        $size = filter_var($tokens, FILTER_VALIDATE_INT);

        if (! is_int($size)) {
            throw new InvalidArgumentException("The anthropic.budget middleware takes its tokens as a whole number, without separators: [{$tokens}] given.");
        }

        $user = $request->user()?->getAuthIdentifier();

        $budget = new Budget(match ($key) {
            'user' => 'user:'.(is_scalar($user) ? $user : $request->ip()),
            'ip' => 'ip:'.$request->ip(),
            default => $key,
        });

        match ($period) {
            'minute' => $budget->perMinute($size),
            'hour' => $budget->perHour($size),
            'day' => $budget->perDay($size),
            default => throw new InvalidArgumentException("The anthropic.budget middleware's window is minute, hour or day: [{$period}] given."),
        };

        $budget->counting($counts)->enforce();

        // BudgetExceeded renders as a 429 with Retry-After.
        Budget::ensureEach(Budget::all());

        /** @var Response */
        return $next($request);
    }
}
