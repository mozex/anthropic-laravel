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
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

expect($response->content[0]->text)->toBe('Hello! How can I help?');
```

`Anthropic::fake()` swaps the real client in the container via `Facade::swap()`, so any code that uses the Facade (or resolves from the container) gets the fake.

## Available fake responses

Every response class has a `fake()` method with sensible defaults:

| Response class | Resource |
|---------------|----------|
| `Messages\CreateResponse::fake()` | Messages create |
| `Messages\CountTokensResponse::fake()` | Token counting |
| `Messages\CreateStreamedResponse::fake()` | Messages streaming |
| `Completions\CreateResponse::fake()` | Completions create |
| `Completions\CreateStreamedResponse::fake()` | Completions streaming |
| `Models\ListResponse::fake()` | Models list |
| `Models\RetrieveResponse::fake()` | Models retrieve |
| `Batches\BatchResponse::fake()` | Batch create/retrieve/cancel |
| `Batches\BatchListResponse::fake()` | Batch list |
| `Batches\BatchResultResponse::fake()` | Batch results |
| `Batches\DeletedBatchResponse::fake()` | Batch delete |

Override only the fields you care about. Everything else gets default values:

```php
Anthropic::fake([
    CreateResponse::fake([
        'id' => 'msg_test_123',
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
    ]),
]);
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
        && $parameters['model'] === 'claude-sonnet-4-6';
});

// Exact count
Anthropic::assertSent(Messages::class, 2);
```

### assertNotSent

```php
Anthropic::assertNotSent(\Anthropic\Resources\Completions::class);
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

Anthropic::completions()->assertNotSent();
```

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
use Anthropic\Exceptions\ErrorException;

Anthropic::fake([
    new ErrorException([
        'message' => 'Overloaded',
        'type' => 'overloaded_error',
    ], 529),
]);

// This throws ErrorException
Anthropic::messages()->create([...]);
```

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
                'model' => 'claude-sonnet-4-6',
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
