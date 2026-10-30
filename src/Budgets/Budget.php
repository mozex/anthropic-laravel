<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Budgets;

use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Laravel\Exceptions\BudgetExceeded;
use Anthropic\Laravel\Ledger\Attribution;
use Anthropic\Responses\Messages\CreateResponseUsage;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * A token budget under a key of the app's choosing (`user:7`, `team:3`), on Laravel's rate limiter: `perDay(200_000)`
 * sets its size and window, `counting()` what it counts. `enforce()` makes the calls after it, in this request or
 * job and the jobs it dispatches, throw `BudgetExceeded` before they're sent once it's used up, and charges each one
 * as it's billed. The windows are fixed: a budget resets a whole window after its first charge.
 */
class Budget
{
    private int $tokens = 0;

    private int $seconds = 86400;

    private string $counts = 'billed';

    public function __construct(public readonly string $key) {}

    public function perMinute(int $tokens): self
    {
        return $this->limit($tokens, 60);
    }

    public function perHour(int $tokens): self
    {
        return $this->limit($tokens, 3600);
    }

    public function perDay(int $tokens): self
    {
        return $this->limit($tokens, 86400);
    }

    /**
     * What a call spends: `billed`, every token billed (input, cache writes, cache reads and output), the default; or
     * `rate`, what rate limits count, which leaves cache reads out.
     */
    public function counting(string $counts): self
    {
        if (! in_array($counts, ['billed', 'rate'], true)) {
            throw new InvalidArgumentException("A budget counts `billed` or `rate` tokens, counting('{$counts}') given.");
        }

        $this->counts = $counts;

        return $this;
    }

    public function remaining(): int
    {
        return max(0, $this->tokens - $this->spent());
    }

    public function exceeded(): bool
    {
        // A counter whose window has passed is reset here, so it never reads as used up with nothing to wait for.
        return RateLimiter::tooManyAttempts($this->limiterKey(), $this->sized());
    }

    /**
     * The seconds until the window resets.
     */
    public function availableIn(): int
    {
        return RateLimiter::availableIn($this->limiterKey());
    }

    /**
     * The tokens this budget counts in a call's usage (as `UsageRecorded` carries it, or the DTO).
     *
     * @param  array<array-key, mixed>|CreateResponseUsage  $usage
     */
    public function tokensOf(array|CreateResponseUsage $usage): int
    {
        $usage = is_array($usage) ? self::usage($usage) : $usage;

        return $this->counts === 'rate' ? $usage->rateLimitTokens() : $usage->billedTokens();
    }

    /**
     * Charges a call's usage, or a count of tokens, and returns the tokens it counted.
     *
     * @param  int|array<array-key, mixed>|CreateResponseUsage  $usage
     */
    public function charge(int|array|CreateResponseUsage $usage): int
    {
        $tokens = is_int($usage) ? $usage : $this->tokensOf($usage);

        if ($tokens > 0) {
            RateLimiter::increment($this->limiterKey(), $this->seconds, $tokens);
        }

        return $tokens;
    }

    /**
     * Charges best effort: a cache that fails is reported, and never fails a call the API already billed.
     *
     * @internal
     *
     * @param  int|array<array-key, mixed>|CreateResponseUsage  $usage
     */
    public function chargeQuietly(int|array|CreateResponseUsage $usage): void
    {
        try {
            $this->charge($usage);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function clear(): void
    {
        RateLimiter::clear($this->limiterKey());
    }

    /**
     * Throws `BudgetExceeded` when it's used up.
     */
    public function ensure(): void
    {
        self::ensureEach([$this]);
    }

    /**
     * Holds the calls after this one to the budget, beside any other enforced: each is refused before it's sent once
     * one is used up, and charged to all of them as it's billed. Kept in hidden Context, so it follows the jobs
     * dispatched after it; enforcing the same key and window again replaces the one before.
     */
    public function enforce(): self
    {
        Attribution::addBudget(['key' => $this->key, 'tokens' => $this->sized(), 'seconds' => $this->seconds, 'counts' => $this->counts]);

        return $this;
    }

    /**
     * Holds only the calls the callback makes to the budget, beside any already enforced, then puts back what was
     * enforced before: for a loop over users, where each `enforce()` would add to the last. A stream is charged when it
     * ends, so read it inside the callback.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function during(Closure $callback): mixed
    {
        $before = Attribution::budgets();
        $this->enforce();

        try {
            return $callback();
        } finally {
            Attribution::setBudgets($before);
        }
    }

    /**
     * The budgets a stored list names, as `enforce()` keeps them; the ones enforced now without one.
     *
     * @internal
     *
     * @param  array<array-key, mixed>|null  $budgets
     * @return list<self>
     */
    public static function all(?array $budgets = null): array
    {
        $all = [];

        foreach ($budgets ?? Attribution::current()['budgets'] as $budget) {
            if (is_array($budget) && ($instance = self::fromArray($budget)) !== null) {
                $all[] = $instance;
            }
        }

        return $all;
    }

    /**
     * Throws `BudgetExceeded` when any of these budgets is used up, for the one that resets last, so a job released
     * for it comes back once every one has room.
     *
     * @internal
     *
     * @param  list<self>  $budgets
     */
    public static function ensureEach(array $budgets): void
    {
        $refused = null;

        foreach ($budgets as $budget) {
            if ($budget->exceeded() && ($refused === null || $budget->availableIn() > $refused->availableIn)) {
                $refused = BudgetExceeded::for($budget->key, $budget->tokens, max(1, $budget->availableIn()));
            }
        }

        if ($refused !== null) {
            throw $refused;
        }
    }

    /**
     * Charges a recorded call to the budgets it was made under, best effort.
     *
     * @internal
     */
    public static function chargeRecorded(UsageRecorded $usage): void
    {
        foreach (self::all($usage->budgets) as $budget) {
            $budget->chargeQuietly($usage->usage);
        }
    }

    /**
     * @param  array<array-key, mixed>  $budget
     */
    private static function fromArray(array $budget): ?self
    {
        if (! is_string($budget['key'] ?? null) || ! is_int($budget['tokens'] ?? null) || ! is_int($budget['seconds'] ?? null)) {
            return null;
        }

        $instance = (new self($budget['key']))->limit($budget['tokens'], $budget['seconds']);
        $instance->counts = ($budget['counts'] ?? null) === 'rate' ? 'rate' : 'billed';

        return $instance;
    }

    private function limit(int $tokens, int $seconds): self
    {
        if ($tokens < 1) {
            throw new InvalidArgumentException("A budget holds at least one token, {$tokens} given.");
        }

        $this->tokens = $tokens;
        $this->seconds = $seconds;

        return $this;
    }

    private function sized(): int
    {
        if ($this->tokens === 0) {
            throw new LogicException("The token budget [{$this->key}] has no size: give it one with perMinute(), perHour() or perDay().");
        }

        return $this->tokens;
    }

    private function spent(): int
    {
        $attempts = RateLimiter::attempts($this->limiterKey());

        return is_numeric($attempts) ? (int) $attempts : 0;
    }

    /**
     * The usage DTO of a usage array as the API sent it, with only the counts the budget reads.
     *
     * @param  array<array-key, mixed>  $usage
     */
    private static function usage(array $usage): CreateResponseUsage
    {
        $counts = fn (array $counts): array => [
            'input_tokens' => is_int($counts['input_tokens'] ?? null) ? $counts['input_tokens'] : 0,
            'output_tokens' => is_int($counts['output_tokens'] ?? null) ? $counts['output_tokens'] : 0,
            'cache_creation_input_tokens' => is_int($counts['cache_creation_input_tokens'] ?? null) ? $counts['cache_creation_input_tokens'] : 0,
            'cache_read_input_tokens' => is_int($counts['cache_read_input_tokens'] ?? null) ? $counts['cache_read_input_tokens'] : 0,
        ];
        $iterations = array_values(array_map(
            fn (array $iteration): array => ['type' => is_string($iteration['type'] ?? null) ? $iteration['type'] : 'message', ...$counts($iteration)],
            array_filter(is_array($usage['iterations'] ?? null) ? $usage['iterations'] : [], is_array(...)),
        ));

        return CreateResponseUsage::from([...$counts($usage), ...($iterations === [] ? [] : ['iterations' => $iterations])]);
    }

    /**
     * Each window size its own counter, so one key budgeted per minute and per day keeps two. A budget without a size
     * has no counter.
     */
    private function limiterKey(): string
    {
        $this->sized();

        return "anthropic-budget:{$this->key}:{$this->seconds}";
    }
}
