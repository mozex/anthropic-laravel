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

| Status | Error type | Typical cause |
|--------|-----------|---------------|
| 400 | `invalid_request_error` | Bad parameters, missing required fields |
| 401 | `authentication_error` | Invalid or missing API key |
| 402 | `billing_error` | Issue with billing or payment information |
| 403 | `permission_error` | API key doesn't have access to the requested resource |
| 404 | `not_found_error` | Invalid model name or resource ID |
| 413 | `request_too_large` | Request exceeds the maximum size |
| 429 | `rate_limit_error` | Too many requests (see below) |
| 500 | `api_error` | Server-side issue |
| 504 | `timeout_error` | Request timed out while processing |
| 529 | `overloaded_error` | API is temporarily overloaded |

## Rate limit errors

HTTP 429 responses throw `RateLimitException`, a subclass of `ErrorException`. Catch it first if you want to handle rate limits specifically:

```php
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Exceptions\ErrorException;

try {
    $response = Anthropic::messages()->create([...]);
} catch (RateLimitException $e) {
    $retryAfter = $e->response->getHeaderLine('Retry-After');
    sleep((int) $retryAfter);
    // Retry
} catch (ErrorException $e) {
    // Other API errors
}
```

## Retrying failed jobs

Queue jobs are a natural fit for retry logic. Combine Laravel's `tries` and `backoff` with rate limit handling:

```php
class AskClaudeJob implements ShouldQueue
{
    public $tries = 5;
    public $backoff = [10, 30, 60, 120, 300];

    public function handle(): void
    {
        try {
            $response = Anthropic::messages()->create([...]);
        } catch (RateLimitException $e) {
            $this->release((int) $e->response->getHeaderLine('Retry-After') ?: 60);
            return;
        } catch (ErrorException $e) {
            if ($e->getStatusCode() === 529) {
                $this->release(30);
                return;
            }

            throw $e;
        }

        // Process response...
    }
}
```

Overload errors (HTTP 529) are expected during peak times. Release the job back to the queue and retry rather than reporting them as exceptions to your error tracker.

## Transport errors

Network failures (DNS, connection refused, timeout) throw `TransporterException`:

```php
use Anthropic\Exceptions\TransporterException;

try {
    $response = Anthropic::messages()->create([...]);
} catch (TransporterException $e) {
    $e->getMessage(); // Underlying HTTP client error
}
```

## Missing API key

If you haven't set `ANTHROPIC_API_KEY`, the first call throws `Anthropic\Laravel\Exceptions\ApiKeyIsMissing`:

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
ErrorException (API errors)
├── RateLimitException (HTTP 429)
TransporterException (HTTP client/network errors)
UnserializableResponse (JSON decode failures)
ApiKeyIsMissing (config-time error)
```

You can silence specific errors globally from Laravel's exception handler. A common case is skipping overload errors so they don't show up in your error tracker every time the API gets busy:

```php
// bootstrap/app.php
use Anthropic\Exceptions\ErrorException;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (ErrorException $e) {
        if ($e->getStatusCode() === 529) {
            return false;
        }
    });
})
```

Returning `false` from a `reportable` callback tells Laravel to stop the default reporting for that exception. Return nothing (or `null`) and it falls through to the normal reporting chain. Extend this pattern for any other status codes you want to handle quietly.

---

For the full error format and request size limits, see the [Error Handling page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/error-handling) or the [Errors reference](https://platform.claude.com/docs/en/api/errors) on the Anthropic docs.
