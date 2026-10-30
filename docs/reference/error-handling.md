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

Queue jobs are a natural fit for retry logic. Combine Laravel's `tries` and `backoff` with rate limit handling:

```php
use Anthropic\Exceptions\OverloadedException;
use Anthropic\Exceptions\RateLimitException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AskClaudeJob implements ShouldQueue
{
    use Queueable;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120, 300];

    public function handle(): void
    {
        try {
            $response = Anthropic::messages()->create([...]);
        } catch (RateLimitException $e) {
            if (($e->getErrorDetails()['error_code'] ?? null) === 'enforced_spend_limit_reached') {
                $this->fail($e); // Monthly spend cap: retrying won't help
                return;
            }

            $this->release($e->getRetryAfter() ?? 60);
            return;
        } catch (OverloadedException $e) {
            $this->release(30);
            return;
        }

        // Process response...
    }
}
```

`getRetryAfter()` returns the `Retry-After` header in seconds, or `null` when the 429 has none. An organization past its tier's monthly spend cap gets none, since waiting won't help until access resumes. `getErrorDetails()` identifies that case, so the job fails instead of burning through its tries.

Overload errors (HTTP 529) are expected during peak times. Release the job back to the queue and retry rather than reporting them as exceptions to your error tracker. Catch `OverloadedException`, or check `getErrorType()`, never the status code: an overload in the middle of a stream comes with status 200 and still throws `OverloadedException`.

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
