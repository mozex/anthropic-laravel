---
title: Error Handling
weight: 2
---

The package throws typed exceptions for API errors, HTTP failures, and configuration problems. Catch them like any other Laravel exception.

## API errors

When the Anthropic API returns an error response, the client throws `Anthropic\Exceptions\ErrorException`:

```php
use Anthropic\Exceptions\ErrorException;
use Anthropic\Laravel\Facades\Anthropic;

try {
    $response = Anthropic::messages()->create([...]);
} catch (ErrorException $e) {
    $e->getMessage();    // 'Overloaded'
    $e->getErrorType();  // 'overloaded_error'
    $e->getStatusCode(); // 529
}
```

The raw PSR-7 response is on the `response` property if you need to inspect headers or body.

### Common API errors

Each status throws its own subclass of `ErrorException` (all in `Anthropic\Exceptions`), so a `catch (ErrorException $e)` still catches everything:

| Status | Error type | Exception | Typical cause |
|--------|-----------|-----------|---------------|
| 400 | `invalid_request_error` | `BadRequestException` | Bad parameters, missing required fields |
| 401 | `authentication_error` | `AuthenticationException` | Invalid or missing API key |
| 402 | `billing_error` | `BillingException` | Issue with billing or payment information |
| 403 | `permission_error` | `PermissionDeniedException` | API key doesn't have access to the requested resource |
| 404 | `not_found_error` | `NotFoundException` | Invalid model name or resource ID |
| 409 | `conflict_error` | `ConflictException` | Conflicts with the resource's current state |
| 413 | `request_too_large` | `RequestTooLargeException` | Request exceeds the maximum size |
| 422 | | `UnprocessableEntityException` | Well formed, but couldn't be processed |
| 429 | `rate_limit_error` | `RateLimitException` | Too many requests (see below) |
| 500 | `api_error` | `InternalServerException` | Server-side issue (other 5xx too) |
| 504 | `timeout_error` | `ApiTimeoutException` | Request timed out while processing |
| 529 | `overloaded_error` | `OverloadedException` | API is temporarily overloaded |

A status without its own class, such as 405, throws a plain `ErrorException`. An error in the middle of a stream throws the class for its error type, even though its status is 200. `$e->getRequestId()` returns the failed request's ID for support, and `$e->getErrorDetails()` returns the error's `details`.

## Rate limit errors

HTTP 429 responses throw `RateLimitException`, a subclass of `ErrorException`. Catch it first if you want to handle rate limits specifically:

```php
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Exceptions\ErrorException;

try {
    $response = Anthropic::messages()->create([...]);
} catch (RateLimitException $e) {
    sleep($e->getRetryAfter() ?? 60);
    // Retry
} catch (ErrorException $e) {
    // Other API errors
}
```

## Retrying failed jobs

`ThrottlesAnthropic` is job middleware that knows Claude's errors. It fails a job the API won't take on a retry and releases one it may take later, waiting what the API asks:

```php
use Anthropic\Laravel\Queue\ThrottlesAnthropic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AskClaudeJob implements ShouldQueue
{
    use Queueable;

    // a bug of the job's own stops it after 3 runs, each of which may have paid for a call
    public $maxExceptions = 3;

    public function middleware(): array
    {
        return [new ThrottlesAnthropic('claude-sonnet-5-5')];
    }

    // each release counts as an attempt, so bound the job by time
    public function retryUntil(): DateTime
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $response = Anthropic::withOptions(['max_retries' => 0])->messages()->create([...]);
    }
}
```

| The API answers | The job |
|-----------------|---------|
| 429 at the monthly spend cap (`enforced_spend_limit_reached`) | fails, and `SpendCapReached` is dispatched |
| Any other 429, a 529 overload, a 408, 409 or 5xx, or no answer | is released for `retry-after`, else until the rate limit resets, else a backoff from 10 seconds that doubles up to 10 minutes |
| 400, 401, 402, 403, 404, 413 or 422 | fails: a retry gets the same answer |

A rate limit or an overload holds back every job with the same key until the wait is over, rather than letting each one hit the limit on its own. Rate limits are per model class, so pass one key for models that share a class (the default key is shared by every job). Anything else a job throws goes on as usual, which `$maxExceptions` stops. The job needs `InteractsWithQueue`, which `Queueable` includes, and it turns the client's own retries off for its call, so the two don't wait on top of each other. Put `ThrottlesAnthropic` last in `middleware()`, so it sees the API's errors before a middleware like `ThrottlesExceptions` turns them into plain retries. On the `sync` queue, or a job run directly, there's nothing to release or fail later, so the error goes to the caller.

Two options act before a job calls:

```php
(new ThrottlesAnthropic('claude-sonnet-5-5'))
    ->budget(20000)     // wait for the reset while the last answer left fewer tokens than this
    ->concurrency(5);   // at most 5 of these jobs call at once (Cache::funnel, a store with locks)
```

`budget()` reads the rate-limit headers of the last answer a throttled job got under that key. The API rounds them to the nearest thousand, so treat the number as a guide.

`SpendCapReached` carries the key, the job's class, the request ID and the API's message, which makes it a good place to tell someone:

```php
use Anthropic\Laravel\Events\SpendCapReached;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

Event::listen(function (SpendCapReached $event) {
    Notification::route('slack', config('services.slack.ops'))->notify(new ClaudeSpendCapReached($event));
});
```

Overload errors (HTTP 529) are expected during peak times, which is why the middleware releases them. Outside a queued job, catch `OverloadedException`, or check `getErrorType()`, never the status code: an overload in the middle of a stream comes with status 200 and still throws `OverloadedException`.

On Laravel 13.30 or later you can set both rules once, in `bootstrap/app.php`: stop retrying a job on errors another try won't fix, and throttle how often overloads reach your error tracker (`throttle()` works on Laravel 12 too):

```php
use Anthropic\Exceptions\AuthenticationException;
use Anthropic\Exceptions\BadRequestException;
use Anthropic\Exceptions\OverloadedException;
use Anthropic\Exceptions\PermissionDeniedException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Configuration\Exceptions;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->dontRetry([BadRequestException::class, AuthenticationException::class, PermissionDeniedException::class]);

    $exceptions->throttle(fn (Throwable $e) => $e instanceof OverloadedException
        ? Limit::perMinute(5)
        : Limit::none());
})
```

## Logs and error trackers

Every API exception has a `context()` method. Laravel's exception handler adds it to the log entry of each reported exception, and Sentry and Flare attach it too, so a logged API error carries its request ID with nothing to set up:

```php
$e->context();
// ['anthropic_request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG', 'status' => 429, 'type' => 'rate_limit_error',
//  'error_code' => 'enforced_spend_limit_reached']
```

It leaves out what the error doesn't carry (a rate limit adds `retry_after`, the spend cap above has none), and holds only those values, never the message, the other headers or a body. Anthropic support asks for the request ID when you report a problem. To find the IDs of the calls that succeeded before an error, turn on [Context](./configuration.md#context).

## Transport errors

Network failures throw `TransporterException`. A failed connection (DNS, refused or dropped) throws its subclass `ConnectionException`, and a timeout throws `TimeoutException`, which extends `ConnectionException`:

```php
use Anthropic\Exceptions\TimeoutException;
use Anthropic\Exceptions\TransporterException;

try {
    $response = Anthropic::messages()->create([...]);
} catch (TimeoutException $e) {
    // Raise ANTHROPIC_REQUEST_TIMEOUT, or stream the response
} catch (TransporterException $e) {
    $e->getMessage(); // Underlying HTTP client error
}
```

## Missing API key

If you've set neither `ANTHROPIC_API_KEY` nor `ANTHROPIC_AUTH_TOKEN`, the first call throws `Anthropic\Laravel\Exceptions\ApiKeyIsMissing`:

```php
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;

try {
    $response = Anthropic::messages()->create([...]);
} catch (ApiKeyIsMissing $e) {
    // API key not configured
}
```

This is a config-time error. Usually you'll catch it once during deployment, not in request handlers.

## Exception hierarchy

```
ErrorException (API errors; one subclass per status, see the table above)
└── BadRequestException (400) ... RateLimitException (429) ... OverloadedException (529)
TransporterException (HTTP client/network errors)
└── ConnectionException
    └── TimeoutException
UnserializableResponse (a 2xx response that isn't valid JSON)
ApiKeyIsMissing (config-time error)
```

A 504 from the API throws `ApiTimeoutException`, an `ErrorException`. The transport `TimeoutException` only covers your HTTP client giving up.

You can silence specific errors globally from Laravel's exception handler. A common case is skipping overload errors so they don't show up in your error tracker every time the API gets busy:

```php
// bootstrap/app.php
use Anthropic\Exceptions\OverloadedException;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->dontReport(OverloadedException::class);
})
```

When the class alone isn't enough, return `false` from a `reportable` callback to stop the default reporting for that exception. Return nothing and it falls through to the normal chain.

---

For the full error format and request size limits, see the [Error Handling page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/error-handling) or the [Errors reference](https://platform.claude.com/docs/en/api/errors) on the Anthropic docs.
