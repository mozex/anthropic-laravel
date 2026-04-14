---
title: Meta Information
weight: 3
---

Every API response includes HTTP headers with rate limit data, request IDs, and token limits. Access them with the `meta()` method on any response.

## Accessing meta information

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);

$meta = $response->meta();
```

## Request ID

```php
$meta->requestId; // 'req_012nTzj6kLoP8vZ1SGANvcgR'
```

Include this when contacting Anthropic support. A common Laravel pattern is to log it alongside every successful response:

```php
Log::info('Claude response', [
    'request_id' => $response->meta()->requestId,
    'input_tokens' => $response->usage->inputTokens,
    'output_tokens' => $response->usage->outputTokens,
]);
```

## Rate limits

```php
$meta->requestLimit->limit;     // 3000
$meta->requestLimit->remaining; // 2999
$meta->requestLimit->reset;     // '2026-04-14T13:29:17Z'

$meta->tokenLimit->limit;     // 250000
$meta->tokenLimit->remaining; // 249984

$meta->inputTokenLimit->limit;
$meta->inputTokenLimit->remaining;

$meta->outputTokenLimit->limit;
$meta->outputTokenLimit->remaining;
```

### Priority Tier limits

If your organization is on [Priority Tier](https://platform.claude.com/docs/en/api/service-tiers), two extra rate-limit buckets appear as typed properties. They stay `null` when the request didn't draw from Priority capacity:

```php
$meta->priorityInputTokenLimit?->limit;
$meta->priorityInputTokenLimit?->remaining;
$meta->priorityInputTokenLimit?->reset;

$meta->priorityOutputTokenLimit?->limit;
$meta->priorityOutputTokenLimit?->remaining;
$meta->priorityOutputTokenLimit?->reset;
```

Useful for monitoring whether a request actually hit your Priority allocation or spilled into the shared standard pool. A quick Laravel-flavored check:

```php
$meta = $response->meta();

if ($meta->priorityInputTokenLimit === null) {
    Log::warning('Claude request did not use Priority Tier', [
        'request_id' => $meta->requestId,
    ]);
}
```

## Throttling based on remaining limits

If you're doing high-volume work, check the remaining requests and throttle before you hit a 429:

```php
$response = Anthropic::messages()->create([...]);

$meta = $response->meta();

if ($meta->requestLimit->remaining < 10) {
    $reset = Carbon::parse($meta->requestLimit->reset);
    Cache::put('anthropic.throttle', true, $reset);
}
```

Your next job can check the cache flag before making a request.

## On streams

Call `meta()` on the stream itself:

```php
$stream = Anthropic::messages()->createStreamed([...]);

$meta = $stream->meta();
```

The meta information comes from HTTP response headers, which arrive at the start of the stream.

## On batch results

Same pattern works on batch results:

```php
$results = Anthropic::batches()->results('msgbatch_...');

foreach ($results as $individual) {
    // Process results
}

$results->meta();
```

---

For the full header list and the raw `toArray()` format, see the [Meta Information page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/meta-information) or the [rate limits guide](https://platform.claude.com/docs/en/api/rate-limits) on the Anthropic docs.
