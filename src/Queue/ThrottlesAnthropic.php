<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Queue;

use Anthropic\Exceptions\ApiTimeoutException;
use Anthropic\Exceptions\AuthenticationException;
use Anthropic\Exceptions\BadRequestException;
use Anthropic\Exceptions\BillingException;
use Anthropic\Exceptions\ConflictException;
use Anthropic\Exceptions\ConnectionException;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Exceptions\InternalServerException;
use Anthropic\Exceptions\NotFoundException;
use Anthropic\Exceptions\OverloadedException;
use Anthropic\Exceptions\PermissionDeniedException;
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Exceptions\RequestTooLargeException;
use Anthropic\Exceptions\UnprocessableEntityException;
use Anthropic\Laravel\Events\SpendCapReached;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Throwable;

/**
 * Job middleware for jobs that call Claude: it fails a job the API won't take (a bad request, a refused key, the
 * monthly spend cap) and releases one it may take later (a rate limit, an overload, a server error, no answer),
 * waiting what the API asks. A rate limit or an overload holds back every job with the same key until then, so a full
 * queue doesn't hit the limit again all at once. Pass one key per rate-limit class: models that share a class share a
 * key.
 */
class ThrottlesAnthropic
{
    private ?int $budget = null;

    private ?int $concurrency = null;

    public function __construct(private readonly string $key = 'default') {}

    /**
     * Releases a job before it calls while the last answer under this key left fewer than `$tokens` tokens of its
     * rate limit, until the limit resets. The API rounds what's left to the nearest thousand, so it's a guide.
     */
    public function budget(int $tokens): self
    {
        $this->budget = $tokens;

        return $this;
    }

    /**
     * Lets at most `$jobs` jobs with this key call at once, through `Cache::funnel()` (a cache store with locks), and
     * releases the rest for a few seconds.
     */
    public function concurrency(int $jobs): self
    {
        if ($jobs < 1) {
            throw new InvalidArgumentException("At least one job calls at a time, concurrency({$jobs}) given.");
        }

        $this->concurrency = $jobs;

        return $this;
    }

    public function handle(object $job, Closure $next): void
    {
        if (! method_exists($job, 'release') || ! method_exists($job, 'fail')) {
            throw new InvalidArgumentException('ThrottlesAnthropic releases and fails the job, so '.$job::class.' needs the InteractsWithQueue trait.');
        }

        // A sync or direct run is never run again after a release, so it calls now instead of waiting.
        $final = self::final($job);
        $wait = $final ? 0 : max($this->cooldown(), $this->budgetWait());

        if ($wait > 0) {
            self::release($job, $wait);

            return;
        }

        if ($this->concurrency === null) {
            $this->call($job, $next);

            return;
        }

        // The slot is held as long as the job may run, so a long call keeps its place.
        $timeout = isset($job->timeout) && is_int($job->timeout) ? $job->timeout : 0;

        Cache::funnel("anthropic:funnel:{$this->key}:")
            ->limit($this->concurrency)
            ->releaseAfter(max(60, $timeout))
            ->block(0)
            // A sync or direct run gets the limiter's timeout: a release would drop it without a word.
            ->then(fn () => $this->call($job, $next), $final ? null : fn () => self::release($job, 5));
    }

    private function call(object $job, Closure $next): void
    {
        $scope = app(ThrottleScope::class);
        $previous = $scope->key;

        if ($this->budget !== null) {
            RecordsBudget::listenOn(app(Dispatcher::class));
        }

        // A throttled job run inside this one gives the key back when it ends.
        $scope->key = $this->budget === null ? null : $this->key;

        try {
            $next($job);
        } catch (Throwable $e) {
            $this->failOrRelease($job, $e);
        } finally {
            $scope->key = $previous;
        }
    }

    /**
     * By the exception's class first, since an error in the middle of a stream comes with a 200.
     */
    private function failOrRelease(object $job, Throwable $e): void
    {
        // A sync or direct run can't be released or failed for later: the error goes to its caller.
        $final = self::final($job);

        if ($e instanceof RateLimitException && ($e->getErrorDetails()['error_code'] ?? null) === 'enforced_spend_limit_reached') {
            try {
                app(Dispatcher::class)->dispatch(new SpendCapReached($this->key, $job::class, $e->getRequestId(), $e->getErrorMessage()));
            } catch (Throwable $listener) {
                // The job fails even when a listener throws, or the worker would retry it into the cap again; a caller
                // still gets the 429, and the listener's error is reported.
                if (! $final) {
                    self::fail($job, $e);

                    throw $listener;
                }

                report($listener);
            }

            if ($final) {
                throw $e;
            }

            self::fail($job, $e);

            return;
        }

        if (self::retryable($e)) {
            if ($e instanceof RateLimitException || $e instanceof OverloadedException) {
                $this->holdBack($e);
            }

            if ($final) {
                throw $e;
            }

            self::release($job, $this->apiWait($e) ?? self::backoff($job));

            return;
        }

        if (! $final && self::permanent($e)) {
            self::fail($job, $e);

            return;
        }

        throw $e;
    }

    /**
     * No answer, or one a retry may change: a 429, 529, 5xx, 409 or 408.
     */
    private static function retryable(Throwable $e): bool
    {
        return $e instanceof ConnectionException || $e instanceof RateLimitException || $e instanceof OverloadedException
            || $e instanceof InternalServerException || $e instanceof ApiTimeoutException || $e instanceof ConflictException
            || ($e instanceof ErrorException && $e->getStatusCode() === 408);
    }

    /**
     * The 4xx a retry gets again: 400, 401, 402, 403, 404, 413 and 422.
     */
    private static function permanent(Throwable $e): bool
    {
        return $e instanceof BadRequestException || $e instanceof AuthenticationException || $e instanceof BillingException
            || $e instanceof PermissionDeniedException || $e instanceof NotFoundException || $e instanceof RequestTooLargeException
            || $e instanceof UnprocessableEntityException;
    }

    /**
     * Holds back every job of the key for what the API asked, else the first backoff: one job's attempts never set the
     * key's wait, or a long overload would ratchet it up. Only ever lengthened (a race between workers can still
     * shorten it), so a short wait never cuts a rate limit's short.
     */
    private function holdBack(Throwable $e): void
    {
        $wait = $this->apiWait($e) ?? 10;

        if ($wait > $this->cooldown()) {
            Cache::put("anthropic:throttle:{$this->key}", Date::now()->getTimestamp() + $wait, $wait);
        }
    }

    /**
     * What the API asks: `retry-after`, else a rate limit's reset.
     */
    private function apiWait(Throwable $e): ?int
    {
        $retryAfter = $e instanceof ErrorException ? $e->getRetryAfter() : null;

        if ($retryAfter !== null && $retryAfter > 0) {
            return $retryAfter;
        }

        // A rate limit's reset says when it lifts; an overload or a server error has no such time.
        if ($e instanceof RateLimitException && $e->response !== null) {
            $reset = $e->response->getHeaderLine('anthropic-ratelimit-tokens-reset') ?: $e->response->getHeaderLine('anthropic-ratelimit-requests-reset');
            $resetAt = $reset === '' ? false : strtotime($reset);
            $seconds = $resetAt === false ? 0 : $resetAt - Date::now()->getTimestamp();

            if ($seconds > 0) {
                return $seconds;
            }
        }

        return null;
    }

    /**
     * A backoff that doubles from 10 seconds to 10 minutes by the job's attempts.
     */
    private static function backoff(object $job): int
    {
        $attempts = method_exists($job, 'attempts') ? $job->attempts() : 1;
        $attempts = is_int($attempts) ? $attempts : 1;

        return (int) min(10 * 2 ** min(max($attempts - 1, 0), 10), 600);
    }

    /**
     * Whether the job runs where a release or a failure means nothing: the sync queue, or called directly.
     */
    private static function final(object $job): bool
    {
        $queued = property_exists($job, 'job') ? $job->job : null;

        return $queued === null || $queued instanceof SyncJob;
    }

    private static function release(object $job, int $seconds): void
    {
        if (method_exists($job, 'release')) {
            $job->release($seconds);
        }
    }

    private static function fail(object $job, Throwable $e): void
    {
        if (method_exists($job, 'fail')) {
            $job->fail($e);
        }
    }

    /**
     * The seconds left before this key's jobs call again.
     */
    private function cooldown(): int
    {
        // Redis gives a stored number back as a string.
        $until = Cache::get("anthropic:throttle:{$this->key}");

        return is_numeric($until) ? max(0, (int) $until - Date::now()->getTimestamp()) : 0;
    }

    private function budgetWait(): int
    {
        if ($this->budget === null) {
            return 0;
        }

        $last = Cache::get("anthropic:budget:{$this->key}");

        if (! is_array($last) || ! is_int($last['remaining'] ?? null) || ! is_int($last['reset'] ?? null) || $last['remaining'] >= $this->budget) {
            return 0;
        }

        return max(0, $last['reset'] - Date::now()->getTimestamp());
    }
}
