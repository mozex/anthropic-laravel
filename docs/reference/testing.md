---
title: Testing
weight: 4
---

The `Anthropic` Facade ships with a `fake()` method that swaps the real client for a fake one. Queue fake responses, run your code, then assert which requests were sent. No HTTP mocking libraries needed.

## Setting up the fake client

Call `Anthropic::fake()` in your test with an array of fake responses:

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Messages\CreateResponse;

Anthropic::fake([
    CreateResponse::fake([
        'content' => [['type' => 'text', 'text' => 'Hello! How can I help?']],
    ]),
]);

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

expect($response->content[0]->text)->toBe('Hello! How can I help?');
```

`Anthropic::fake()` replaces the real client on the Facade and in the container, so code that uses the Facade, or has `Anthropic\Contracts\ClientContract` injected, gets the fake:

```php
use Anthropic\Contracts\ClientContract;

class SummarizeArticle
{
    public function __construct(private ClientContract $client) {}
}
```

Type-hint `ClientContract` rather than `Anthropic\Client`. The fake isn't a `Client`, so resolving a dependency typed with the class after `Anthropic::fake()` throws a `TypeError`.

`Anthropic::fake()` with no responses answers every call with its method's default fake, so a test of a job that only needs the call to succeed queues nothing. Once you queue a response, a call with none left throws. To make an unanswered call throw even with nothing queued, call `preventStrayRequests()`:

```php
Anthropic::fake()->preventStrayRequests();
```

To answer by what was called rather than in order, use `respondTo()` with a resource, a resource and method pair, or a closure:

```php
use Anthropic\Resources\Models;
use Anthropic\Responses\Models\RetrieveResponse;

Anthropic::fake()->respondTo([Models::class, 'retrieve'], RetrieveResponse::fake());
```

A responder answers every matching call. A stream is read only once, so pass a closure that builds a fresh one each time, as the [PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/testing#answering-by-resource-method-or-closure) show.

## Available fake responses

Every response class has a `fake()` method with sensible defaults:

| Response class | Resource |
|---------------|----------|
| `Messages\CreateResponse::fake()` | Messages create |
| `Messages\CountTokensResponse::fake()` | Token counting |
| `Messages\CreateStreamedResponse::fake()` | Messages streaming |
| `Completions\CreateResponse::fake()` | Completions create (deprecated) |
| `Completions\CreateStreamedResponse::fake()` | Completions streaming (deprecated) |
| `Models\ListResponse::fake()` | Models list |
| `Models\RetrieveResponse::fake()` | Models retrieve |
| `Batches\BatchResponse::fake()` | Batch create/retrieve/cancel |
| `Batches\BatchListResponse::fake()` | Batch list |
| `Batches\BatchResultResponse::fake()` | Batch results |
| `Batches\DeletedBatchResponse::fake()` | Batch delete |
| `Files\FileResponse::fake()` | Files upload/retrieve metadata |
| `Files\FileListResponse::fake()` | Files list |
| `Files\DeletedFileResponse::fake()` | Files delete |
| `Raw\RawResponse::fake()` | `Anthropic::request()` |

Override only the fields you care about. Everything else gets default values:

```php
Anthropic::fake([
    CreateResponse::fake([
        'id' => 'msg_test_123',
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
    ]),
]);
```

`Raw\RawResponse::fake()` takes the whole body and a status rather than overrides, since a raw response has no defaults to merge into.

`iterate()` on the fake reads one queued page per `list` request:

```php
use Anthropic\Responses\Models\ListResponse;

Anthropic::fake([
    ListResponse::fake(['has_more' => true, 'last_id' => 'claude-sonnet-4-6']),
    ListResponse::fake(), // the default fake has has_more false, so this is the last page
]);
```

The fake answers every connection too, `withApiKey()` and `organization()` included, and `assertSentOn()` checks which one a call used:

```php
use Anthropic\Resources\Messages;

$fake = Anthropic::fake([CreateResponse::fake()]);

Anthropic::connection('support')->messages()->create([...]);

$fake->assertSentOn('support', Messages::class);
```

## Assertions

### assertSent

Check that a request was made to a specific resource:

```php
use Anthropic\Resources\Messages;

// Any request was sent
Anthropic::assertSent(Messages::class);

// With a callback
Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
    return $method === 'create'
        && $parameters['model'] === 'claude-sonnet-5-5';
});

// Exact count
Anthropic::assertSent(Messages::class, 2);
```

### assertNotSent

```php
Anthropic::assertNotSent(\Anthropic\Resources\Batches::class);
```

### assertNothingSent

```php
Anthropic::assertNothingSent();
```

### assertSentTimes and assertSentInOrder

```php
Anthropic::assertSentTimes(Messages::class, 2);

Anthropic::assertSentInOrder([
    [\Anthropic\Resources\Models::class, 'retrieve'],
    [Messages::class, 'create'],
]);
```

`assertSentInOrder()` checks every request that was sent, so an extra call anywhere fails it; a failure lists the calls. `Anthropic::sent(Messages::class)` returns a resource's requests, so you can check their arguments yourself.

Under Pest, keep the fake `Anthropic::fake()` returns and pass it to `expect()`, since calling `Anthropic::fake()` again starts an empty one:

```php
$fake = Anthropic::fake();

// run your code...

expect($fake)->toHaveSent(Messages::class)->toHaveSentOn('support', Messages::class);
```

`toHaveSentTimes()`, `toHaveSentInOrder()` and `toHaveSentNothing()` work the same way.

### Resource-level assertions

You can also assert on the resource directly:

```php
Anthropic::messages()->assertSent(function (string $method, array $parameters): bool {
    return $method === 'create';
});

Anthropic::batches()->assertNotSent();
```

### assertSentWithOptions

Code that calls `Anthropic::withOptions([...])` gets a fake sharing the same queue and records, and each request it sends records its options:

```php
Anthropic::assertSentWithOptions(Messages::class, [
    'workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ',
]);
```

A request with more options than you list still matches.

## Testing controllers

A full example of a controller test:

```php
it('asks Claude a question', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'content' => [['type' => 'text', 'text' => 'Paris is the capital of France.']],
        ]),
    ]);

    $response = $this->post('/ask', ['question' => 'What is the capital of France?']);

    $response->assertOk();
    $response->assertSee('Paris');

    Anthropic::assertSent(Messages::class, function (string $method, array $parameters) {
        return $parameters['messages'][0]['content'] === 'What is the capital of France?';
    });
});
```

## Testing queued jobs

With `Queue::fake()`, jobs don't run by default. Use `dispatchSync` to run them immediately:

```php
it('processes a Claude job', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'content' => [['type' => 'text', 'text' => 'Answer']],
        ]),
    ]);

    AskClaudeJob::dispatchSync('What is 2+2?');

    expect(Answer::count())->toBe(1);
    expect(Answer::first()->content)->toBe('Answer');

    Anthropic::assertSent(Messages::class);
});
```

## Testing errors

Queue an exception. It's thrown when the matching request runs, and each API exception's `fake()` builds it as the client would, with its status, type, message and `request-id` on a real PSR-7 response:

```php
use Anthropic\Exceptions\OverloadedException;

Anthropic::fake([OverloadedException::fake()]);

// This throws OverloadedException
Anthropic::messages()->create([...]);
```

A job that releases on a 429 can be tested with `\Anthropic\Exceptions\RateLimitException::fake(headers: ['retry-after' => '30'])`, and one that fails on the monthly spend cap with `RateLimitException::fake(['details' => ['error_code' => 'enforced_spend_limit_reached']])`. `\Anthropic\Exceptions\ConnectionException::fake()` and `TimeoutException::fake()` stand for a request that got no answer. Fake the class the client would throw: a plain `ErrorException` doesn't reach a `catch (OverloadedException $e)`.

## Testing streamed responses

Pass `CreateStreamedResponse::fake()` the response it should stream, and the fake sends the events the API would for it, text deltas, thinking and tool input included:

```php
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Testing\Blocks;

it('streams the reply', function () {
    Anthropic::fake([
        CreateStreamedResponse::fake(CreateResponse::fake([
            'content' => [Blocks::text('Hello, world!')],
        ])),
    ]);

    $text = '';

    foreach (Anthropic::messages()->createStreamed([...]) as $event) {
        if ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
            $text .= $event->delta->text;
        }
    }

    expect($text)->toBe('Hello, world!');
});
```

For exact control, pass a list of event arrays instead, or a file resource of recorded SSE. `Blocks` also builds `toolUse()`, `thinking()`, `serverToolUse()` and `webSearchToolResult()` blocks for a fake's `content`, and `BatchResultResponse::fake([...])` takes the result lines a batch's results would hold.

## Record and replay

`Anthropic::record()` runs a test against the real API once, saves the answers to a cassette file, then replays them on every later run, with no HTTP call and no key:

```php
use Anthropic\Laravel\Facades\Anthropic;

test('it summarizes an article', function () {
    Anthropic::record(base_path('tests/cassettes/summary.json'));

    $summary = app(SummarizeArticle::class)->handle($article);

    expect($summary)->toContain('Laravel');
});
```

It rebuilds the default connection from your config with the recorder as its innermost middleware, so your events, listeners and fallback models run on the recorded answers as they would on live ones. The first run needs `ANTHROPIC_API_KEY`; commit the cassette, and CI replays it. Pass `record: true` to record again. A cassette keeps each answer's body and the headers the client reads, never your key or your prompts.

Only the default connection is recorded: the facade's calls and an injected `ClientContract`. `Anthropic::connection()`, `withApiKey()` and `organization()` still reach the API, so fake those with `Anthropic::fake()`, and a recorded test asserts on what your code returned, since the fake's `assertSent()` isn't there. Give each test, and each dataset row, its own cassette: a replay checks each request's method and path, not its body. See the [PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/testing#record-and-replay) for what a cassette holds.

## Adding responses dynamically

If you need to add more fake responses after setup:

```php
$fake = Anthropic::fake([CreateResponse::fake(['content' => [['type' => 'text', 'text' => 'First']]])]);

// Run some code that uses the first response...

$fake->addResponses([
    CreateResponse::fake(['content' => [['type' => 'text', 'text' => 'Second']]]),
]);

// Run code that uses the second response...
```

If your code makes more requests than you have fake responses, the next call throws "No fake responses left.", naming the call. That's usually a sign that you need to add more fakes or that your code is making unexpected calls.

---

For the full list of response classes, the recursive merge strategy for overrides, and more testing patterns, see the [Testing page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/testing).
