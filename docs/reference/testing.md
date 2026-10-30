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

Pass an exception as a fake response. It will be thrown when the matching request runs:

```php
use Anthropic\Exceptions\OverloadedException;
use GuzzleHttp\Psr7\Response;

Anthropic::fake([
    new OverloadedException([
        'message' => 'Overloaded',
        'type' => 'overloaded_error',
    ], new Response(529)),
]);

// This throws OverloadedException
Anthropic::messages()->create([...]);
```

Fake the class the client would throw for that status: a plain `ErrorException` doesn't reach a `catch (OverloadedException $e)`. Pass a PSR-7 response as the second argument, as the client does, so the code under test can read headers from `$e->response`. A job that releases on a 429 can be tested with `new RateLimitException([...], new Response(429, ['retry-after' => '30']))`. An `int` status code in place of the response is deprecated.

## Testing streamed responses

For streaming tests, pass a file resource:

```php
use Anthropic\Responses\Messages\CreateStreamedResponse;

Anthropic::fake([
    CreateStreamedResponse::fake(fopen('tests/fixtures/stream.txt', 'r')),
]);
```

Or build a stream from an array of text chunks with this helper:

```php
// In tests/Pest.php or a test helper file
function fakeStream(array $parts)
{
    $events = collect($parts)
        ->map(fn (string $part) => [
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $part],
        ])
        ->prepend([
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-5-5',
                'content' => [],
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
            ],
        ])
        ->add([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null],
            'usage' => ['output_tokens' => 12],
        ])
        ->add(['type' => 'message_stop'])
        ->map(fn (array $event) => "event: {$event['type']}\ndata: " . json_encode($event))
        ->join("\n\n");

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $events);
    rewind($handle);

    return $handle;
}
```

Use it in a test:

```php
it('streams text chunks', function () {
    Anthropic::fake([
        CreateStreamedResponse::fake(fakeStream(['Hello', ', ', 'world!'])),
    ]);

    $stream = Anthropic::messages()->createStreamed([...]);

    $text = '';
    foreach ($stream as $event) {
        if ($event->type === 'content_block_delta'
            && $event->delta->type === 'text_delta') {
            $text .= $event->delta->text;
        }
    }

    expect($text)->toBe('Hello, world!');
});
```

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

If your code makes more requests than you have fake responses, the client throws a "No fake responses left" exception. That's usually a sign that you need to add more fakes or that your code is making unexpected calls.

---

For the full list of response classes, the recursive merge strategy for overrides, and more testing patterns, see the [Testing page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/testing).
