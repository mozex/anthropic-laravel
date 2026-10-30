<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Queue;

use Anthropic\Laravel\Budgets\Budget;
use Anthropic\Laravel\Exceptions\BudgetExceeded;
use Anthropic\Laravel\Ledger\Attribution;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Job middleware that holds a job to a token budget, beside any it inherited from where it was dispatched (or only to
 * those, given none): a used-up one releases the job until it resets, before it runs or partway through (on the sync
 * queue, or run directly, it throws `BudgetExceeded` instead). The caller's budgets are put back when the job ends, as
 * the sync queue runs it in the caller's process.
 * `new TokenBudget(Anthropic::budget('team:'.$this->team->id)->perDay(1_000_000))`.
 */
class TokenBudget
{
    /**
     * SQS holds a released job at most 12 hours from when it handed it over, so a longer wait comes back and checks
     * again.
     */
    private const LONGEST_RELEASE = 21600;

    public function __construct(private readonly ?Budget $budget = null) {}

    public function handle(object $job, Closure $next): void
    {
        $before = Attribution::budgets();
        $this->budget?->enforce();

        try {
            Budget::ensureEach(Budget::all());

            $next($job);
        } catch (BudgetExceeded $e) {
            $queued = property_exists($job, 'job') ? $job->job : null;

            // A sync or direct run is never run again after a release, so it gets the exception.
            if ($queued === null || $queued instanceof SyncJob || ! method_exists($job, 'release')) {
                throw $e;
            }

            $job->release(min(max(1, $e->availableIn), self::LONGEST_RELEASE));
        } finally {
            Attribution::setBudgets($before);
        }
    }
}
