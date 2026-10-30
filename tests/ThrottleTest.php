<?php

declare(strict_types=1);

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
use Anthropic\Laravel\Events\ResponseReceived;
use Anthropic\Laravel\Events\SpendCapReached;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Queue\ThrottlesAnthropic;
use Anthropic\Laravel\Queue\ThrottleScope;
use Anthropic\Responses\Messages\CreateResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Limiters\LimiterTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A job that calls Claude, with no queue job behind it: run directly.
 */
function claudeJob(): object
{
    return new class implements ShouldQueue
    {
        use InteractsWithQueue;
        use Queueable;
    };
}

/**
 * A queued job that calls Claude, its queue interactions faked so a test can assert a release or a failure.
 */
function throttledJob(int $attempts = 1): object
{
    $job = claudeJob();

    $job->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    return $job;
}

/**
 * Runs the job through the middleware, its call throwing what's given, and says whether the call ran.
 */
function throttle(ThrottlesAnthropic $middleware, object $job, ?Throwable $throws = null): bool
{
    $called = false;

    $middleware->handle($job, function () use (&$called, $throws): void {
        $called = true;

        if ($throws !== null) {
            throw $throws;
        }
    });

    return $called;
}

beforeEach(function () {
    $this->freezeTime();
    Cache::flush();
});

test('a spend-cap 429 fails the job and dispatches SpendCapReached', function () {
    Event::fake([SpendCapReached::class]);
    $job = throttledJob();

    // the spend-cap error of https://platform.claude.com/docs/en/api/rate-limits, as the testing docs fake it
    throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), $job, RateLimitException::fake(['details' => ['error_code' => 'enforced_spend_limit_reached']]));

    $job->assertFailed();
    Event::assertDispatched(SpendCapReached::class, fn (SpendCapReached $event): bool => $event->key === 'claude-sonnet-5-5'
        && $event->job === $job::class
        && $event->requestId !== null);
});

test('another 429 releases the job for its retry-after, and holds back every job of the same key until then', function () {
    $first = throttledJob();

    throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), $first, RateLimitException::fake(headers: ['retry-after' => '30']));

    $first->assertReleased(delay: 30);

    $sameKey = throttledJob();
    expect(throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), $sameKey))->toBeFalse();
    $sameKey->assertReleased(delay: 30);

    // another key, another limit
    expect(throttle(new ThrottlesAnthropic('claude-haiku-5-5'), throttledJob()))->toBeTrue();

    $this->travel(31)->seconds();
    expect(throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), throttledJob()))->toBeTrue();
});

test('a 429 without retry-after waits for the rate limit to reset', function () {
    $job = throttledJob();

    throttle(new ThrottlesAnthropic, $job, RateLimitException::fake(headers: ['anthropic-ratelimit-tokens-reset' => now()->addSeconds(40)->toIso8601ZuluString()]));

    $job->assertReleased(delay: 40);
});

test('an overload releases with a backoff that doubles by attempt, and an overload in a stream counts too', function (int $attempts, int $delay) {
    $job = throttledJob($attempts);

    // a mid-stream error comes with a 200, so only its class says what it is
    throttle(new ThrottlesAnthropic, $job, new OverloadedException(['type' => 'overloaded_error', 'message' => 'Overloaded'], 200));

    $job->assertReleased(delay: $delay);
})->with([
    'the first attempt' => [1, 10],
    'the third' => [3, 40],
    'the tenth' => [10, 600],
]);

test('no answer releases the job', function () {
    $job = throttledJob();

    throttle(new ThrottlesAnthropic, $job, ConnectionException::fake());

    $job->assertReleased(delay: 10);
});

test('an error the API won\'t take on a retry fails the job', function (Throwable $error) {
    $job = throttledJob();

    throttle(new ThrottlesAnthropic, $job, $error);

    $job->assertFailed();
})->with([
    'a bad request' => [fn () => BadRequestException::fake()],
    'a refused key' => [fn () => AuthenticationException::fake()],
    'a missing model' => [fn () => NotFoundException::fake()],
]);

test('anything else is thrown as it was', function () {
    throttle(new ThrottlesAnthropic, throttledJob(), new LogicException('a bug of the job\'s own'));
})->throws(LogicException::class, 'a bug of the job\'s own');

test('the budget holds back a job while the last answer under its key left too few tokens, until the reset', function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray(), 200, [
        // composed: a rate limit nearly spent, resetting in a minute
        'anthropic-ratelimit-tokens-remaining' => '1000',
        'anthropic-ratelimit-tokens-reset' => now()->addMinute()->toIso8601ZuluString(),
    ])]);
    $middleware = fn (): ThrottlesAnthropic => (new ThrottlesAnthropic('claude-sonnet-5-5'))->budget(5000);

    $middleware()->handle(throttledJob(), fn () => Anthropic::messages()->create(['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]]));

    $next = throttledJob();
    expect(throttle($middleware(), $next))->toBeFalse();
    $next->assertReleased(delay: 60);

    // without a budget, or under another key, nothing holds it back
    expect(throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), throttledJob()))->toBeTrue()
        ->and(throttle((new ThrottlesAnthropic('claude-haiku-5-5'))->budget(5000), throttledJob()))->toBeTrue();
    Http::assertSentCount(1);
});

test('a call without a budget records none, nor does one outside a throttled job', function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray(), 200, ['anthropic-ratelimit-tokens-remaining' => '0', 'anthropic-ratelimit-tokens-reset' => now()->addMinute()->toIso8601ZuluString()])]);

    (new ThrottlesAnthropic('claude-sonnet-5-5'))->handle(throttledJob(), fn () => Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']));
    Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']);

    expect(Cache::get('anthropic:budget:claude-sonnet-5-5'))->toBeNull();
});

test('concurrency lets that many jobs of a key call at once, and releases the rest', function () {
    $held = Cache::lock('anthropic:funnel:claude-sonnet-5-5:1', 60);
    $held->get();
    $job = throttledJob();

    expect(throttle((new ThrottlesAnthropic('claude-sonnet-5-5'))->concurrency(1), $job))->toBeFalse();
    $job->assertReleased(delay: 5);

    expect(throttle((new ThrottlesAnthropic('claude-sonnet-5-5'))->concurrency(2), throttledJob()))->toBeTrue();

    $held->release();
});

test('a cooldown stored as a string, as Redis gives it back, still holds jobs back', function () {
    Cache::put('anthropic:throttle:claude-sonnet-5-5', (string) (now()->getTimestamp() + 30), 30);
    $job = throttledJob();

    expect(throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), $job))->toBeFalse();
    $job->assertReleased(delay: 30);
});

test('an overload holds back the key too, and a shorter wait never cuts a longer one short', function () {
    throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), throttledJob(), RateLimitException::fake(headers: ['retry-after' => '60']));
    throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), throttledJob(), OverloadedException::fake());

    expect(Cache::get('anthropic:throttle:claude-sonnet-5-5'))->toBe(now()->getTimestamp() + 60);

    throttle(new ThrottlesAnthropic('claude-haiku-5-5'), throttledJob(), OverloadedException::fake());

    expect(Cache::get('anthropic:throttle:claude-haiku-5-5'))->toBe(now()->getTimestamp() + 10);
});

test('retry-after wins over the reset, and an overload backs off even with a reset header', function () {
    $limited = throttledJob();
    throttle(new ThrottlesAnthropic, $limited, RateLimitException::fake(headers: ['retry-after' => '15', 'anthropic-ratelimit-tokens-reset' => now()->addMinute()->toIso8601ZuluString()]));
    $limited->assertReleased(delay: 15);

    $overloaded = throttledJob();
    throttle(new ThrottlesAnthropic('another'), $overloaded, OverloadedException::fake(headers: ['anthropic-ratelimit-tokens-reset' => now()->addMinute()->toIso8601ZuluString()]));
    $overloaded->assertReleased(delay: 10);
});

test('the other statuses a retry may fix release the job, and those it can\'t fail it', function (Throwable $error, bool $released) {
    $job = throttledJob();

    throttle(new ThrottlesAnthropic, $job, $error);

    $released ? $job->assertReleased() : $job->assertFailed();
})->with([
    'a server error' => [fn () => InternalServerException::fake(), true],
    'a gateway timeout' => [fn () => ApiTimeoutException::fake(), true],
    'a conflict' => [fn () => ConflictException::fake(), true],
    'a request timeout' => [fn () => ErrorException::fake(status: 408), true],
    'billing' => [fn () => BillingException::fake(), false],
    'a permission' => [fn () => PermissionDeniedException::fake(), false],
    'too large' => [fn () => RequestTooLargeException::fake(), false],
    'unprocessable' => [fn () => UnprocessableEntityException::fake(), false],
]);

test('a job run directly or on the sync queue calls now and gets the error, since nothing would run it again', function (Closure $job) {
    Event::fake([SpendCapReached::class]);
    $final = $job();

    // a 429 goes to the caller, and still holds back the key
    expect(fn () => throttle(new ThrottlesAnthropic, $final, RateLimitException::fake(headers: ['retry-after' => '30'])))->toThrow(RateLimitException::class)
        ->and(Cache::get('anthropic:throttle:default'))->toBe(now()->getTimestamp() + 30);

    // the cooldown doesn't hold it back: it calls, and gets the API's errors
    expect(fn () => throttle(new ThrottlesAnthropic, $final, BadRequestException::fake()))->toThrow(BadRequestException::class)
        ->and(fn () => throttle(new ThrottlesAnthropic, $final, RateLimitException::fake(['details' => ['error_code' => 'enforced_spend_limit_reached']])))->toThrow(RateLimitException::class);

    Event::assertDispatched(SpendCapReached::class);

    // a full funnel says so instead of releasing it
    $held = Cache::lock('anthropic:funnel:default:1', 60);
    $held->get();

    expect(fn () => throttle((new ThrottlesAnthropic)->concurrency(1), $final))->toThrow(LimiterTimeoutException::class);

    $held->release();
})->with([
    'run directly' => [fn () => claudeJob()],
    'on the sync queue' => [fn () => claudeJob()->setJob(new SyncJob(app(), '{}', 'sync', 'sync'))],
]);

test('on a run nothing would repeat, a SpendCapReached listener that throws is reported and the 429 still reaches the caller', function () {
    Event::listen(SpendCapReached::class, fn () => throw new RuntimeException('Slack is down'));

    expect(fn () => throttle(new ThrottlesAnthropic, claudeJob(), RateLimitException::fake(['details' => ['error_code' => 'enforced_spend_limit_reached']])))->toThrow(RateLimitException::class);
});

test('a long overload never ratchets up the key\'s wait: the key waits the first backoff, the job its own', function () {
    $job = throttledJob(attempts: 5);

    throttle(new ThrottlesAnthropic('claude-sonnet-5-5'), $job, OverloadedException::fake());

    $job->assertReleased(delay: 160);
    expect(Cache::get('anthropic:throttle:claude-sonnet-5-5'))->toBe(now()->getTimestamp() + 10);
});

test('a SpendCapReached listener that throws still leaves the job failed', function () {
    Event::listen(SpendCapReached::class, fn () => throw new RuntimeException('Slack is down'));
    $job = throttledJob();

    expect(fn () => throttle(new ThrottlesAnthropic, $job, RateLimitException::fake(['details' => ['error_code' => 'enforced_spend_limit_reached']])))->toThrow(RuntimeException::class, 'Slack is down');

    $job->assertFailed();
});

test('the budget listener is added by the first job with a budget, once, and a throttled job inside another gives the key back', function () {
    (new ThrottlesAnthropic('no-budget'))->handle(throttledJob(), fn () => null);

    // a job without a budget leaves the event unbuilt for an app that doesn't listen
    expect(Event::hasListeners(ResponseReceived::class))->toBeFalse();

    $outer = (new ThrottlesAnthropic('outer'))->budget(1000);
    $outer->handle(throttledJob(), function () {
        (new ThrottlesAnthropic('inner'))->budget(1000)->handle(throttledJob(), fn () => null);

        expect(app(ThrottleScope::class)->key)->toBe('outer');
    });

    expect(app(ThrottleScope::class)->key)->toBeNull()
        ->and(app('events')->getListeners(ResponseReceived::class))->toHaveCount(1);
});

test('a job that can\'t be released is refused, and concurrency takes at least one job', function () {
    expect(fn () => (new ThrottlesAnthropic)->handle(new stdClass, fn () => null))->toThrow(InvalidArgumentException::class, 'needs the InteractsWithQueue trait')
        ->and(fn () => (new ThrottlesAnthropic)->concurrency(0))->toThrow(InvalidArgumentException::class, 'concurrency(0) given');
});
